<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\Account;
use AA\Isds\AuthException;
use AA\Isds\ActionResult;
use AA\Isds\Actions;
use AA\Isds\Advice;
use AA\Isds\Drafts;
use AA\Isds\Gate;
use AA\Isds\IsdsException;
use AA\Isds\Outcome;
use AA\Isds\RecipientFilter;
use AA\Isds\Recipients;
use AA\Isds\Registry\Sync;
use AA\Isds\Schema;
use AA\Isds\SendQueue;
use AA\Isds\SendResult;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Setup;
use AA\Isds\Snapshot;
use AA\Isds\TestResult;
use AA\Http\Response;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\FakeActions;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\MemoryDrafts;
use Tests\Unit\Isds\Fixtures\ScriptedIsds;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendQueue;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';
require_once __DIR__ . '/fixtures/FakeActions.php';
require_once __DIR__ . '/fixtures/FakeSliceRules.php';
require_once __DIR__ . '/fixtures/FakeRecipients.php';
require_once __DIR__ . '/fixtures/TestSendQueue.php';
require_once __DIR__ . '/fixtures/MemoryDrafts.php';

/**
 * The actions of a page of a mass sending: only a POST of this origin with the token of the form the
 * gate gave for the action, the binding of the page and the campaign acts - once a request, with the
 * login of the request. Continue, Stop and Abandon reach the real run (SendRun over the tables in memory);
 * Send and Test a core given by each test, two tabs sending the real SendQueue.
 */
final class ActionsTest extends TestCase
{
    use SendRunCase {
        setUp as private caseSetUp;
        tearDown as private caseTearDown;
    }

    private const OTHER_ITEM = 'ca000000000000000000000000000c02';

    /** an item of the source slice */
    private const RECIPIENT = '50000000000000000000000000000e01';

    /** @var array<string, array{slice: string, active: bool, filter: string, state: string}> what the gate reads of an item */
    private array $campaigns = [];

    /** @var array<string, mixed> $_SERVER before the test */
    private array $server = [];

    /** the upload directory of the campaign slice, when a test sends through the real SendQueue */
    private string $uploads = '';

    protected function setUp(): void
    {
        $this->caseSetUp();
        FakeActions::reset();
        FakeActions::$resume = static fn(string $campaign, string $uid): string => TestSendRun::resumeFor($campaign, $uid);
        FakeActions::$stop   = static fn(string $campaign, string $uid, bool $abandon): string => TestSendRun::stop($campaign, $uid, $abandon);
        FakeActions::$verify = static fn(string $campaign, string $uid, string $msg): string => TestSendRun::verify($campaign, $uid, $msg);
        $this->server        = $_SERVER;
        $this->campaigns     = [self::CAMPAIGN_ITEM => self::item()];
        session_id('actions-test-session');
    }

    protected function tearDown(): void
    {
        if ($this->uploads !== '') {
            array_map('unlink', glob("$this->uploads/*") ?: []);
            rmdir($this->uploads);
            FakeSliceRules::forget();
            FakeRecipients::forget();
            AA::Contentcache()->clear();
        }
        $_SERVER  = $this->server;
        $_POST    = [];
        $_GET     = [];
        $_COOKIE  = [];
        $_REQUEST = [];
        $_FILES   = [];
        FakeActions::reset();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
        }
        $this->caseTearDown();
    }

    /** @return array{slice: string, active: bool, filter: string, state: string} */
    private static function item(string $slice = Sending::CAMPAIGN, bool $active = true): array
    {
        return ['slice' => $slice, 'active' => $active, 'filter' => '', 'state' => 'sending'];
    }

    /** the page of SITE sending from the campaign slice into the message slice, in the test ISDS */
    private static function page(string $environment = Account::TEST, string $message = Sending::MESSAGE): Setup
    {
        return Setup::fromSnapshot(Sending::SITE, Sending::CAMPAIGN, $message, Sending::SOURCE, ['mesto' => 'Cities'], environment: $environment);
    }

    /** @param ?\Closure(Setup, string): ?ActionResult $answered what the same form did before; null = nothing */
    private function actions(string $login = Sending::USER, ?\Closure $answered = null): FakeActions
    {
        return new FakeActions(
            FakePerms::login($login),
            fn(Setup $setup, string $id): ?array => (($this->campaigns[$id]['slice'] ?? '') === $setup->campaignSlice) ? $this->campaigns[$id] : null,
            static fn(string $id): ?SendRun => TestSendRun::ofSubject($id, 1)[0] ?? null,
            $answered ?? static fn(): ?ActionResult => null,
            static fn(): Recipients => throw new \LogicException('This test picks no item'),
        );
    }

    /**
     * The form of the page posted from it: the action on the campaign with the token for $setup
     * @param array<string, mixed> $over fields instead; null leaves one out
     */
    private static function post(string $action = 'resume', ?Setup $setup = null, string $campaign = self::CAMPAIGN_ITEM, array $over = []): void
    {
        $_SERVER['REQUEST_METHOD']      = 'POST';
        $_SERVER['HTTP_HOST']           = 'www.example.cz';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $fields                         = ['isds_action' => $action, 'isds_campaign' => $campaign, 'isds_token' => FormToken::get(Gate::purpose($action, $setup ?? self::page(), $campaign))];
        $_POST                          = array_filter(array_replace($fields, $over), static fn(mixed $v): bool => !is_null($v));
    }

    /** a run of the campaign in error, by the binding of $props over the page's */
    private static function inError(array $props = []): string
    {
        return self::leftInError(self::queued(3, $props));
    }

    private static function assertExpired(?ActionResult $result): void
    {
        self::assertEquals(new ActionResult('', ActionResult::EXPIRED, Actions::EXPIRED), $result);
        self::assertSame([[], [], []], [FakeActions::$resumed, FakeActions::$stopped, FakeActions::$logged], 'nothing asked of the core, nothing logged');
    }

    // ------------------------------------------------------------ the frame

    public function test_run_getChangesNothing(): void
    {
        $id = self::inError();
        self::post();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET                      = $_POST;

        self::assertNull($this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$resumed);
        self::assertSame(SendRun::ERROR, self::stored($id)['state']);
    }

    public function test_run_noTokenExpired(): void
    {
        self::inError();
        self::post(over: ['isds_token' => null]);

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    public function test_run_tokenOfOtherActionExpired(): void
    {
        self::inError();
        self::post(over: ['isds_token' => FormToken::get(Gate::purpose('stop', self::page(), self::CAMPAIGN_ITEM))]);

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    public function test_run_tokenOfOtherCampaignExpired(): void
    {
        self::inError();
        self::post(over: ['isds_token' => FormToken::get(Gate::purpose('resume', self::page(), self::OTHER_ITEM))]);

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    /** @return array<string, array{Setup}> a page of the same campaign slice by another binding */
    public static function otherBinding(): array
    {
        return [
            'production'            => [self::page(Account::PRODUCTION)],
            'another message slice' => [self::page(message: Sending::OTHER)],
            'another site'          => [Setup::fromSnapshot(Sending::SITE2, Sending::CAMPAIGN, Sending::MESSAGE, Sending::SOURCE, ['mesto' => 'Cities'])],
        ];
    }

    // a form of the test page sends nothing from the production page of the same campaign slice
    #[DataProvider('otherBinding')]
    public function test_run_tokenOfOtherBindingExpired(Setup $other): void
    {
        self::inError();
        self::post('resume', $other);

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    public function test_run_unknownActionExpired(): void
    {
        self::inError();
        self::post('delete');

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    /** @return array<string, array{string}> */
    public static function foreignFetch(): array
    {
        return ['a sibling subdomain' => ['same-site'], 'another site' => ['cross-site']];
    }

    // SameSite=Lax cookies ride a POST of a sibling *.ecn.cz
    #[DataProvider('foreignFetch')]
    public function test_run_sameSiteFetchExpired(string $site): void
    {
        self::inError();
        self::post();
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $site;

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    // an older browser sends no Sec-Fetch-Site, only the origin
    public function test_run_foreignOriginExpired(): void
    {
        self::inError();
        self::post();
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $_SERVER['HTTP_ORIGIN'] = 'https://cizi.example';

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    // a proxy AA does not trust leaves its own host in HTTP_HOST: the origin of the page is foreign then
    public function test_run_originBehindUntrustedProxyExpired(): void
    {
        self::inError();
        self::post();
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $_SERVER['HTTP_ORIGIN'] = 'https://www.example.cz';
        $_SERVER['HTTP_HOST']   = 'proxyaa.ecn.cz';

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    public function test_run_cookieDoesNotActAsPost(): void
    {
        $id = self::inError();
        self::post();
        $_COOKIE  = $_POST;
        $_REQUEST = $_POST;
        $_POST    = [];

        self::assertNull($this->actions()->run(self::page(), true));
        self::assertSame(SendRun::ERROR, self::stored($id)['state']);
    }

    // PHP read none of it: the form is lost, and the user is told so
    public function test_run_overPostMaxSizeTellsTooLarge(): void
    {
        self::post();
        $_POST                     = [];
        $_SERVER['CONTENT_LENGTH'] = (string) (FakeActions::$postMaxSize + 1);

        self::assertEquals(new ActionResult('', ActionResult::REFUSED, Actions::TOO_LARGE, params: [FakeActions::$postMaxSize]), $this->actions()->run(self::page(), true));
    }

    /** @return array<string, array{\Closure(): void, ?string}> */
    public static function notTooLarge(): array
    {
        return [
            'another form of the page'    => [static function (): void {
                $_POST                     = ['comment' => 'x'];
                $_SERVER['CONTENT_LENGTH'] = '9';
            }, null],
            'up to the limit'             => [static function (): void {
                $_POST                     = [];
                $_SERVER['CONTENT_LENGTH'] = (string) FakeActions::$postMaxSize;
            }, null],
            'no limit'                    => [static function (): void {
                $_POST                     = [];
                $_SERVER['CONTENT_LENGTH'] = '999999999';
                FakeActions::$postMaxSize  = 0;
            }, null],
            'too large from another site' => [static function (): void {
                $_POST                          = [];
                $_SERVER['CONTENT_LENGTH']      = '999999999';
                $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
            }, Actions::EXPIRED],
        ];
    }

    /** @param \Closure(): void $request */
    #[DataProvider('notTooLarge')]
    public function test_run_tooLargeOnlyOverTheLimitFromThisSite(\Closure $request, ?string $code): void
    {
        self::post();
        $request();

        self::assertSame($code, $this->actions()->run(self::page(), true)?->code);
    }

    // two gates on the page: the second one takes no form once the first acted
    public function test_run_oneActionPerRequest(): void
    {
        self::inError();
        self::post();

        self::assertSame(Actions::RESUMED, $this->actions()->run(self::page(), true)?->code);
        self::assertNull($this->actions()->run(self::page(), true));
        self::assertCount(1, FakeActions::$resumed);
    }

    /** @return array<string, array{array<string, array{slice: string, active: bool, filter: string, state: string}>}> */
    public static function notOfThePage(): array
    {
        return [
            'no such item'             => [[]],
            'an item of another slice' => [[self::CAMPAIGN_ITEM => self::item(Sending::OTHER)]],
            'an item of the messages'  => [[self::CAMPAIGN_ITEM => self::item(Sending::MESSAGE)]],
        ];
    }

    /** @param array<string, array{slice: string, active: bool, filter: string, state: string}> $campaigns */
    #[DataProvider('notOfThePage')]
    public function test_run_campaignOfOtherSliceUnknown(array $campaigns): void
    {
        self::inError();
        $this->campaigns = $campaigns;
        self::post();

        self::assertEquals(new ActionResult('resume', ActionResult::REFUSED, Actions::UNKNOWN, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$resumed);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function runOfOtherBinding(): array
    {
        return [
            'another message slice'  => [['msg_slice' => Sending::OTHER]],
            'another campaign slice' => [['camp_slice' => Sending::OTHER]],
        ];
    }

    // another binding may share the campaign slice: its run is not this page's to control
    /** @param array<string, string> $props */
    #[DataProvider('runOfOtherBinding')]
    public function test_run_runOfOtherBindingUnknown(array $props): void
    {
        $id = self::inError($props);
        self::post();

        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame(SendRun::ERROR, self::stored($id)['state']);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function runOfTheSlices(): array
    {
        return [
            // a binding switched between test and production: the run of the old environment
            'another environment'                   => [['env' => Account::PRODUCTION]],
            // an account of several sites: a run queued on the page of one goes on from another
            'queued on another site of the binding' => [['site' => Sending::SITE2]],
        ];
    }

    /** @param array<string, string> $props */
    #[DataProvider('runOfTheSlices')]
    public function test_run_runOfThePageSlicesActs(array $props): void
    {
        self::inError($props);
        self::post();

        self::assertSame(Actions::RESUMED, $this->actions()->run(self::page(), true)?->code);
    }

    // ?debug printed before the gate: the page went out without the headers against framing
    public function test_run_unguardedPageExpired(): void
    {
        self::inError();
        self::post();

        self::assertExpired($this->actions()->run(self::page(), false));
    }

    // a double click, a POST reloaded: the first result, the core not asked again
    public function test_run_sameFormTwiceActsOnce(): void
    {
        self::inError();
        self::post();
        $first  = new ActionResult('resume', ActionResult::DONE, Actions::RESUMED, self::CAMPAIGN_ITEM);
        $asked  = [];
        $before = function (Setup $setup, string $uid) use ($first, &$asked): ActionResult {
            $asked[] = [$setup->bindingKey(), $uid];

            return $first;
        };

        self::assertSame($first, $this->actions(answered: $before)->run(self::page(), true));
        self::assertSame([[self::page()->bindingKey(), Sending::USER]], $asked);
        self::assertSame([[], []], [FakeActions::$resumed, FakeActions::$logged]);
        self::assertNull($this->actions()->run(self::page(), true), 'once a request');
    }

    // the sending goes on: a log row that could not be written does not tell it failed
    public function test_run_logFailureAfterDoneKeepsTheResult(): void
    {
        self::inError();
        FakeActions::$logFails = new \RuntimeException('log full');
        self::post();

        self::assertSame(Actions::RESUMED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([], FakeActions::$failures);
    }

    public function test_run_uidFromLoginNotRequest(): void
    {
        self::inError();
        self::post(over: ['uid' => Sending::READER, 'isds_uid' => Sending::READER, 'user' => Sending::READER]);
        $_SERVER['PHP_AUTH_USER'] = Sending::READER;

        $this->actions()->run(self::page(), true);

        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER]], FakeActions::$resumed);
        self::assertSame([[SendQueue::LOG_EVENT, 'resume:' . self::CAMPAIGN_ITEM, [Sending::USER, Actions::RESUMED]]], FakeActions::$logged);
    }

    // the token is derived from the session: without one a form of no page fits
    public function test_run_noSessionExpired(): void
    {
        self::inError();
        self::post();
        session_id('');
        $_POST['isds_token'] = FormToken::get(Gate::purpose('resume', self::page(), self::CAMPAIGN_ITEM));

        self::assertSame('', $_POST['isds_token']);
        self::assertExpired($this->actions()->run(self::page(), true));
    }

    public function test_run_arrayFieldsExpired(): void
    {
        self::inError();
        self::post(over: ['isds_action' => ['resume'], 'isds_token' => ['x']]);

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    public function test_run_exceptionHidesItsText(): void
    {
        self::inError();
        FakeActions::$resume = static fn(): string => throw new \RuntimeException('password Test-Pa55-never-shown in /secret/path');
        self::post();

        $result = $this->actions()->run(self::page(), true);

        self::assertEquals(new ActionResult('resume', ActionResult::FAILED, Actions::INTERNAL, self::CAMPAIGN_ITEM), $result);
        self::assertSame([], FakeActions::$logged, 'no row of a done Continue');
    }

    public function test_run_exceptionLogsFailureParamsOnly(): void
    {
        FakeActions::$resume = static fn(): string => throw new \RuntimeException('password Test-Pa55-never-shown');
        self::inError();
        self::post();

        $this->actions()->run(self::page(), true);

        self::assertCount(1, FakeActions::$failures);
        [$selector, $params] = FakeActions::$failures[0];
        self::assertSame('resume:' . self::CAMPAIGN_ITEM, $selector);
        self::assertMatchesRegularExpression('/^#' . Sending::SITE . ' RuntimeException .*ActionsTest\.php:\d+$/', $params);
        self::assertStringNotContainsString('never-shown', $params);
    }

    // ------------------------------------------------------------ Continue

    /**
     * The states of a campaign as its page shows them (Gate::progress()), and what Continue does in each
     * @return array<string, array{?\Closure(string): void, string, string, string}> how the run is left (null = no run),
     *         the login, the code, the status
     */
    public static function resumeStates(): array
    {
        $set     = static fn(array $props): \Closure => static function (string $id) use ($props): void {
            foreach ($props as $property => $value) {
                self::storedSet($id, $property, $value);
            }
            if (($props['state'] ?? '') !== '') {
                TableDb::$tables['toexecute'] = [];
            }
        };
        $watch   = ['watch_from' => (string) (self::NOW - 3600), 'dm_ids' => '{"0":"1001","1":"1002","2":"1003"}', 'cursor' => '3', 'position' => '{"p":"watch","n":2}'];
        $waiting = ['next_at' => (string) (self::NOW + 600)];

        return [
            'draft: no run'                     => [null, Sending::USER, Actions::WRONG_STATE, ActionResult::REFUSED],
            'sending, its step due now'         => [$set([]), Sending::USER, Actions::WRONG_STATE, ActionResult::REFUSED],
            'sending, waiting for a retry'      => [$set($waiting), Sending::USER, Actions::RESUMED, ActionResult::DONE],
            'sending, its step still working'   => [$set(['lease' => (string) (self::NOW + 100), 'lease_tok' => 'working-step']), Sending::USER, Actions::LEASE, ActionResult::REFUSED],
            'error'                             => [$set(['state' => SendRun::ERROR]), Sending::USER, Actions::RESUMED, ActionResult::DONE],
            'watching, waiting for its round'   => [$set($watch + $waiting), Sending::USER, Actions::RESUMED, ActionResult::DONE],
            'watching, its round due now'       => [$set($watch), Sending::USER, Actions::WRONG_STATE, ActionResult::REFUSED],
            'watch_error'                       => [$set($watch + ['state' => SendRun::ERROR]), Sending::USER, Actions::RESUMED, ActionResult::DONE],
            'done'                              => [$set(['state' => SendRun::DONE]), Sending::USER, Actions::WRONG_STATE, ActionResult::REFUSED],
            'abandoned'                         => [$set(['state' => SendRun::ENDED]), Sending::USER, Actions::WRONG_STATE, ActionResult::REFUSED],
            'error, another request holds it'   => [static function (string $id) use ($set): void {
                $set(['state' => SendRun::ERROR])($id);
                TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];
            }, Sending::USER, Actions::BUSY, ActionResult::BUSY],
            'error, the right taken away since' => [$set(['state' => SendRun::ERROR]), Sending::READER, Actions::FORBIDDEN, ActionResult::REFUSED],
        ];
    }

    /** @param ?\Closure(string): void $leave */
    #[DataProvider('resumeStates')]
    public function test_resume_byState(?\Closure $leave, string $login, string $code, string $status): void
    {
        if ($leave) {
            $leave(self::queued(3));
        }
        self::post();

        self::assertEquals(new ActionResult('resume', $status, $code, self::CAMPAIGN_ITEM), $this->actions($login)->run(self::page(), true));
        self::assertSame(($code === Actions::RESUMED) ? [[SendQueue::LOG_EVENT, 'resume:' . self::CAMPAIGN_ITEM, [Sending::USER, Actions::RESUMED]]] : [], FakeActions::$logged, 'only a Continue done is logged');
    }

    public function test_resume_goesOn(): void
    {
        $id = self::inError();
        self::post();

        $this->actions()->run(self::page(), true);

        self::assertSame(SendRun::SENDING, self::stored($id)['state']);
        self::assertCount(1, self::queue(), 'its step queued');
    }

    // a campaign put in the trash still shows its sending, which must still be controlled
    public function test_resume_trashedCampaignStillResumes(): void
    {
        self::inError();
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::post();

        self::assertSame(Actions::RESUMED, $this->actions()->run(self::page(), true)?->code);
    }

    // ------------------------------------------------------------ Stop and Abandon

    /**
     * The states of a campaign as its page shows them (Gate::progress()), and what Stop and Abandon do in each
     * @return array<string, array{?\Closure(string): void, string, string, string}> how the run is left (null = no run),
     *         the login, the code of Stop, the code of Abandon
     */
    public static function runStates(): array
    {
        $set     = static fn(array $props): \Closure => static function (string $id) use ($props): void {
            foreach ($props as $property => $value) {
                self::storedSet($id, $property, $value);
            }
            if (($props['state'] ?? '') !== '') {
                TableDb::$tables['toexecute'] = [];
            }
        };
        $tried   = self::tried(3);
        $watch   = self::watched(3) + ['position' => '{"p":"watch","n":2}'];
        $stopped = ['stop_by' => Sending::USER];
        $error   = ['state' => SendRun::ERROR];

        return [
            'draft: no run'                     => [null, Sending::USER, Actions::WRONG_STATE, Actions::WRONG_STATE],
            'sending'                           => [$set([]), Sending::USER, Actions::STOPPING, Actions::WRONG_STATE],
            'sending, stopped already'          => [$set($stopped), Sending::USER, Actions::STOPPING, Actions::WRONG_STATE],
            'sending, every message tried'      => [$set($tried), Sending::USER, Actions::ALL_SENT, Actions::WRONG_STATE],
            'error'                             => [$set($error), Sending::USER, Actions::STOPPING, Actions::WRONG_STATE],
            'error, its step still working'     => [$set($error + ['lease' => (string) (self::NOW + 100), 'lease_tok' => 'working-step']), Sending::USER, Actions::LEASE, Actions::WRONG_STATE],
            'error, stopped already'            => [$set($error + $stopped), Sending::USER, Actions::CONFIRM_ABANDON, Actions::ABANDONED],
            'error, every message tried'        => [$set($error + $tried), Sending::USER, Actions::CONFIRM_ABANDON, Actions::ABANDONED],
            'watching'                          => [$set($watch), Sending::USER, Actions::ALL_SENT, Actions::WRONG_STATE],
            'watch_error'                       => [$set($watch + $error), Sending::USER, Actions::CONFIRM_ABANDON, Actions::ABANDONED],
            'done'                              => [$set(['state' => SendRun::DONE]), Sending::USER, Actions::WRONG_STATE, Actions::WRONG_STATE],
            'abandoned'                         => [$set(['state' => SendRun::ENDED] + $stopped), Sending::USER, Actions::WRONG_STATE, Actions::WRONG_STATE],
            'error, another request holds it'   => [static function (string $id) use ($set, $error, $stopped): void {
                $set($error + $stopped)($id);
                TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];
            }, Sending::USER, Actions::BUSY, Actions::BUSY],
            'error, the right taken away since' => [$set($error + $stopped), Sending::READER, Actions::FORBIDDEN, Actions::FORBIDDEN],
        ];
    }

    /** @param ?\Closure(string): void $leave */
    #[DataProvider('runStates')]
    public function test_stop_byState(?\Closure $leave, string $login, string $stop, string $abandon): void
    {
        $this->assertRunAction('stop', $leave, $login, $stop, Actions::STOPPING);
    }

    /** @param ?\Closure(string): void $leave */
    #[DataProvider('runStates')]
    public function test_abandon_byState(?\Closure $leave, string $login, string $stop, string $abandon): void
    {
        $this->assertRunAction('abandon', $leave, $login, $abandon, Actions::ABANDONED);
    }

    /** @param ?\Closure(string): void $leave */
    private function assertRunAction(string $action, ?\Closure $leave, string $login, string $code, string $done): void
    {
        $id = null;
        if ($leave) {
            $leave($id = self::queued(3));
        }
        $before = is_null($id) ? null : self::stored($id);
        self::post($action);

        $status = match ($code) {
            $done        => ActionResult::DONE,
            Actions::BUSY => ActionResult::BUSY,
            default      => ActionResult::REFUSED,
        };
        self::assertEquals(new ActionResult($action, $status, $code, self::CAMPAIGN_ITEM), $this->actions($login)->run(self::page(), true));
        self::assertSame(($code === $done) ? [[SendQueue::LOG_EVENT, "$action:" . self::CAMPAIGN_ITEM, [Sending::USER, $done]]] : [], FakeActions::$logged, 'only an action done is logged');
        if (!is_null($id) && ($code !== $done)) {
            self::assertSame($before, self::stored($id), 'refused: nothing written');
        }
    }

    // a form of Stop shown before the run fell into error, or in another tab: it asks, it never gives the run up
    public function test_stop_staleFormNeverAbandons(): void
    {
        $id = self::inError(['stop_by' => Sending::USER]);
        self::post('stop');

        self::assertSame(Actions::CONFIRM_ABANDON, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER, false]], FakeActions::$stopped);
        self::assertSame(SendRun::ERROR, self::stored($id)['state']);
        self::assertSame([], FakeActions::$logged);
    }

    // the token binds the action: a form of Stop posted as Abandon is no form
    public function test_abandon_stopTokenExpired(): void
    {
        self::inError(['stop_by' => Sending::USER]);
        self::post('abandon', over: ['isds_token' => FormToken::get(Gate::purpose('stop', self::page(), self::CAMPAIGN_ITEM))]);

        self::assertExpired($this->actions()->run(self::page(), true));
    }

    // a campaign put in the trash still shows its sending, which must still be stopped and given up
    public function test_stop_trashedCampaignStillStops(): void
    {
        $id              = self::queued(3);
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::post('stop');

        self::assertSame(Actions::STOPPING, $this->actions()->run(self::page(), true)?->code);
        self::assertSame(Sending::USER, self::stored($id)['stop_by']);
    }

    public function test_abandon_trashedCampaignStillAbandons(): void
    {
        $id              = self::inError(['stop_by' => Sending::USER]);
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::post('abandon');

        self::assertSame(Actions::ABANDONED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame(SendRun::ENDED, self::stored($id)['state']);
    }

    // the campaign item put back to draft by hand: the run decides, not the item
    public function test_abandon_draftWithRunFollowsTheRun(): void
    {
        self::inError(['stop_by' => Sending::USER]);
        $this->campaigns = [self::CAMPAIGN_ITEM => ['state' => 'draft'] + self::item()];
        self::post('abandon');

        self::assertSame(Actions::ABANDONED, $this->actions()->run(self::page(), true)?->code);
    }

    /** @param array<string, string> $props */
    #[DataProvider('runOfOtherBinding')]
    public function test_abandon_runOfOtherBindingUnknown(array $props): void
    {
        $id = self::inError(['stop_by' => Sending::USER] + $props);
        self::post('abandon');

        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[], SendRun::ERROR], [FakeActions::$stopped, self::stored($id)['state']]);
    }

    // a binding switched between test and production: the run of the old environment can still be ended
    public function test_abandon_runOfAnotherEnvironmentActs(): void
    {
        self::inError(['stop_by' => Sending::USER, 'env' => Account::PRODUCTION]);
        self::post('abandon');

        self::assertSame(Actions::ABANDONED, $this->actions()->run(self::page(), true)?->code);
    }

    // ------------------------------------------------------------ Verify

    /**
     * The states of a campaign as its page shows them (Gate::progress()), and what Verify of the message
     * of recipient 1 does in each
     * @return array<string, array{?\Closure(): string, string, string}> the run (null = none), the login, the code
     */
    public static function verifyStates(): array
    {
        $run   = static fn(array $props = [], array $position = self::SEND, string $state = SendRun::SENDING): \Closure => static function () use ($props, $position, $state): string {
            $id = self::queued(3, $props + ['dm_ids' => '{"0":"1001"}', 'uncertain' => '{"1":' . (self::NOW - 7200) . '}', 'cursor' => '2'], $position);
            self::items()->mark(self::message($id, 1), Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::NOW - 7200);
            self::items()->writes = [];

            return ($state === SendRun::SENDING) ? $id : self::leftInError($id, state: $state);
        };
        $watch = ['cursor' => '3', 'watch_from' => (string) (self::NOW - 3600)];

        return [
            'draft: no run'                     => [null, Sending::USER, Actions::WRONG_STATE],
            'sending'                           => [$run(), Sending::USER, Actions::WRONG_STATE],
            'sending, stopped: looking it up'   => [$run(['stop_by' => Sending::USER], self::LOOK), Sending::USER, Actions::WRONG_STATE],
            'error'                             => [$run([], self::SEND, SendRun::ERROR), Sending::USER, Actions::WRONG_STATE],
            'watching'                          => [$run($watch, self::WATCH), Sending::USER, Actions::VERIFIED],
            'watch_error'                       => [$run($watch, self::WATCH, SendRun::ERROR), Sending::USER, Actions::VERIFIED],
            'done'                              => [$run(['cursor' => '3'], self::LOOK, SendRun::DONE), Sending::USER, Actions::VERIFIED],
            // its end, which marks such messages uncertain, still waits in the queue
            'abandoned, its end not run'        => [static function () use ($run): string {
                $id = $run(['stop_by' => Sending::USER], self::SEND, SendRun::ERROR)();
                TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true);
                return $id;
            }, Sending::USER, Actions::WRONG_STATE],
            'abandoned, its end run'            => [static function () use ($run): string {
                $id = $run(['stop_by' => Sending::USER], self::SEND, SendRun::ERROR)();
                TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true);
                self::runQueued();
                return $id;
            }, Sending::USER, Actions::VERIFIED],
            'watching, the message known'       => [$run($watch + ['failed' => '{"1":"1214"}'], self::WATCH), Sending::USER, Actions::NOT_UNCERTAIN],
            'watching, another request holds it' => [static function () use ($run, $watch): string {
                TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];
                return $run($watch, self::WATCH)();
            }, Sending::USER, Actions::BUSY],
            'watching, the right taken away'    => [$run($watch, self::WATCH), Sending::READER, Actions::FORBIDDEN],
        ];
    }

    /** @param ?\Closure(): string $run */
    #[DataProvider('verifyStates')]
    public function test_verify_byState(?\Closure $run, string $login, string $code): void
    {
        $id     = $run ? $run() : null;
        $msg    = is_null($id) ? 'a0000000000000000000000000000001' : self::message($id, 1);
        $before = is_null($id) ? null : self::stored($id);
        self::post('verify', over: ['isds_message' => $msg]);

        $status = match ($code) {
            Actions::VERIFIED => ActionResult::DONE,
            Actions::BUSY     => ActionResult::BUSY,
            default           => ActionResult::REFUSED,
        };
        self::assertEquals(new ActionResult('verify', $status, $code, self::CAMPAIGN_ITEM), $this->actions($login)->run(self::page(), true));
        $verified = ($code === Actions::VERIFIED);
        self::assertSame($verified ? [[SendQueue::LOG_EVENT, 'verify:' . self::CAMPAIGN_ITEM, [Sending::USER, $msg]]] : [], FakeActions::$logged, 'only a verification done is logged');
        if (!is_null($id)) {
            self::assertSame($verified ? Schema::MESSAGE_STATE_FAILED : Schema::MESSAGE_STATE_UNCERTAIN, self::state($id, 1));
            if (!$verified) {
                self::assertSame($before, self::stored($id), 'refused: nothing written');
            }
        }
    }

    // a double click past the gate's memory, another tab: told it is done, nothing written nor logged again
    public function test_verify_againDoneNotLogged(): void
    {
        $id = self::verifiable();
        self::post('verify', over: ['isds_message' => self::message($id, 1)]);
        $this->actions()->run(self::page(), true);
        FakeActions::reset();
        FakeActions::$verify = static fn(string $campaign, string $uid, string $msg): string => TestSendRun::verify($campaign, $uid, $msg);
        $before              = self::stored($id);
        self::items()->writes = [];

        self::assertEquals(new ActionResult('verify', ActionResult::DONE, Actions::VERIFIED, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));

        self::assertSame([$before, [], []], [self::stored($id), self::items()->writes, FakeActions::$logged]);
    }

    /** @return array<string, array{mixed}> */
    public static function notAMessage(): array
    {
        return ['none' => [null], 'no id' => ['4711-2'], 'an array' => [['a0000000000000000000000000000001']]];
    }

    #[DataProvider('notAMessage')]
    public function test_verify_notAMessageUnknownMessage(mixed $msg): void
    {
        self::verifiable();
        self::post('verify', over: ['isds_message' => $msg]);

        self::assertEquals(new ActionResult('verify', ActionResult::REFUSED, Actions::UNKNOWN_MESSAGE, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$verified, 'the core not asked');
    }

    // the message id is read from the form in any case, and the core gets the login of the request
    public function test_verify_uidFromLoginMessageNormalized(): void
    {
        $id = self::verifiable();
        self::post('verify', over: ['isds_message' => strtoupper(self::message($id, 1)), 'uid' => Sending::READER]);

        self::assertSame(Actions::VERIFIED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER, strtolower(self::message($id, 1))]], FakeActions::$verified);
    }

    // frozen recipients that do not read back as frozen: no text an editor could act on, nothing written
    public function test_verify_snapshotChangedInternal(): void
    {
        $id                  = self::verifiable();
        FakeActions::$verify = static fn(): string => Snapshot::CHANGED;
        self::post('verify', over: ['isds_message' => self::message($id, 1)]);

        self::assertEquals(new ActionResult('verify', ActionResult::FAILED, Actions::INTERNAL, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$logged);
        self::assertCount(1, FakeActions::$failures, 'the State Check counts it as any internal failure');
        [$selector, $params] = FakeActions::$failures[0];
        self::assertSame('verify:' . self::CAMPAIGN_ITEM, $selector);
        self::assertMatchesRegularExpression('/^#' . Sending::SITE . ' UnexpectedValueException .*Actions\.php:\d+$/', $params);
    }

    // a campaign put in the trash still shows its sending, and its messages of unknown fate
    public function test_verify_trashedCampaignStillVerifies(): void
    {
        $id              = self::verifiable();
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::post('verify', over: ['isds_message' => self::message($id, 1)]);

        self::assertSame(Actions::VERIFIED, $this->actions()->run(self::page(), true)?->code);
    }

    /** @param array<string, string> $props */
    #[DataProvider('runOfOtherBinding')]
    public function test_verify_runOfOtherBindingUnknown(array $props): void
    {
        $id = self::verifiable($props);
        self::post('verify', over: ['isds_message' => self::message($id, 1)]);

        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[], Schema::MESSAGE_STATE_UNCERTAIN], [FakeActions::$verified, self::state($id, 1)]);
    }

    /**
     * A run following delivery, recipient 1 of unknown fate
     * @param array<string, string> $props
     */
    private static function verifiable(array $props = []): string
    {
        $id = self::queued(3, $props + ['dm_ids' => '{"0":"1001","2":"1003"}', 'uncertain' => '{"1":' . (self::NOW - 7200) . '}', 'cursor' => '3', 'watch_from' => (string) (self::NOW - 3600)], self::WATCH);
        self::items()->mark(self::message($id, 1), Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::NOW - 7200);
        self::items()->writes = [];

        return $id;
    }

    // ------------------------------------------------------------ Send and Test

    /** a draft of the page: the core decides, not the item the gate reads */
    private function draftOfPage(bool $active = true): void
    {
        $this->campaigns = [self::CAMPAIGN_ITEM => ['state' => Schema::CAMPAIGN_STATE_DRAFT] + self::item(active: $active)];
    }

    public function test_send_queued(): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => SendStart::queued('run-1');
        self::post('send', over: ['isds_confirm' => '2']);

        self::assertEquals(new ActionResult('send', ActionResult::DONE, Actions::QUEUED, self::CAMPAIGN_ITEM, params: [2]), $this->actions()->run(self::page(), true));
        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER, 2]], FakeActions::$queued);
        self::assertSame([], FakeActions::$logged, 'the core logs the sending queued');
    }

    /** @return array<string, array{mixed}> */
    public static function notACount(): array
    {
        return [
            'none'          => [null],
            'empty'         => [''],
            'zero'          => ['0'],
            'negative'      => ['-1'],
            'a fraction'    => ['2.5'],
            'an exponent'   => ['1e3'],
            'a space'       => [' 2'],
            'a leading 0'   => ['02'],
            'text'          => ['two'],
            'an array'      => [['2']],
            'too large'     => ['9999999999'],
        ];
    }

    // the count is what the user saw: a form that tells none sends nothing
    #[DataProvider('notACount')]
    public function test_send_confirmNotNumberIsInput(mixed $confirm): void
    {
        $this->draftOfPage();
        self::post('send', over: ['isds_confirm' => $confirm]);

        self::assertEquals(new ActionResult('send', ActionResult::REFUSED, Actions::INPUT, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$queued);
    }

    public function test_send_countChangedKeepsConfirmed(): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => SendStart::refused(SendStart::COUNT_CHANGED);
        self::post('send', over: ['isds_confirm' => '41']);

        self::assertEquals(new ActionResult('send', ActionResult::REFUSED, SendStart::COUNT_CHANGED, self::CAMPAIGN_ITEM, params: [41]), $this->actions()->run(self::page(), true));
    }

    public function test_send_tooManyNamesTheLimitOfThePage(): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => SendStart::refused(SendStart::TOO_MANY);
        self::post('send', over: ['isds_confirm' => '41']);

        self::assertSame([Setup::DEFAULT_RECIPIENTS], $this->actions()->run(self::page(), true)?->params);
    }

    /**
     * What a refusal of the core leaves for the page: a detail only where it says what to fix
     * @return array<string, array{SendStart, string, string, list<int|string>}> the refusal, status, detail kept, params
     */
    public static function refusals(): array
    {
        return [
            'busy'                       => [SendStart::refused(SendStart::BUSY), ActionResult::BUSY, '', []],
            'settings: what to fix'      => [SendStart::refused(SendStart::SETTINGS, 'Slice "Rozesílky" has no field X'), ActionResult::REFUSED, 'Slice "Rozesílky" has no field X', []],
            'content: what to fix'       => [SendStart::refused(SendStart::CONTENT, 'The letter is empty'), ActionResult::REFUSED, 'The letter is empty', []],
            'account: of datovka_conf'   => [SendStart::refused(SendStart::ACCOUNT, "Fix accounts['NSZM']['password'] in datovka_conf.php first"), ActionResult::REFUSED, '', []],
            'a filter as stored'         => [SendStart::refused(SendStart::BAD_FILTER, 'type "<script>" is not offered'), ActionResult::REFUSED, '', []],
            'a table'                    => [SendStart::refused(SendStart::NO_TRANSACTION, 'content'), ActionResult::REFUSED, '', []],
            'the server: for a developer' => [SendStart::refused(SendStart::SERVER, 'There is no temporary directory for the PDF letter'), ActionResult::REFUSED, '', []],
            'memory: the MB'             => [SendStart::refused(SendStart::MEMORY, 'mb:300'), ActionResult::REFUSED, '', [300]],
            'memory, no MB told'         => [SendStart::refused(SendStart::MEMORY, 'a lot'), ActionResult::REFUSED, '', []],
            'ISDS refused: the status'   => [SendStart::refused(SendRun::REFUSED, 'status:1201'), ActionResult::REFUSED, '', ['1201']],
            'ISDS refused, no status'    => [SendStart::refused(SendRun::REFUSED), ActionResult::REFUSED, '', ['']],
            'ISDS refused, a bad status' => [SendStart::refused(SendRun::REFUSED, 'status:12<b>'), ActionResult::REFUSED, '', ['']],
            'the login refused'          => [SendStart::refused(Outcome::LOGIN), ActionResult::REFUSED, '', []],
        ];
    }

    /** @param list<int|string> $params */
    #[DataProvider('refusals')]
    public function test_send_refusalTellsWhatToFix(SendStart $start, string $status, string $detail, array $params): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => $start;
        self::post('send', over: ['isds_confirm' => '2']);

        self::assertEquals(new ActionResult('send', $status, $start->code, self::CAMPAIGN_ITEM, $detail, $params), $this->actions()->run(self::page(), true));
    }

    // the detail may quote stored data: the page escapes it (GateTest), no log ever holds it
    public function test_send_detailNeverLogged(): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => SendStart::refused(SendStart::CONTENT, 'The file https://example.org/img_upload/abc/tajne-heslo.pdf cannot be read');
        self::post('send', over: ['isds_confirm' => '2']);

        self::assertStringContainsString('tajne-heslo', $this->actions()->run(self::page(), true)->detail ?? '');
        self::assertSame([[], []], [FakeActions::$logged, FakeActions::$failures]);
    }

    public function test_send_exceptionInternal(): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => throw new \RuntimeException('SQL with Test-Pa55-never-shown');
        self::post('send', over: ['isds_confirm' => '2']);

        self::assertEquals(new ActionResult('send', ActionResult::FAILED, Actions::INTERNAL, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertStringNotContainsString('never-shown', FakeActions::$failures[0][1]);
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function sendAndTest(): array
    {
        return ['send' => ['send', ['isds_confirm' => '2']], 'test' => ['test', ['isds_box' => 'dj7j6mk']]];
    }

    // a draft put in the trash is no campaign of the page to send
    /** @param array<string, string> $fields */
    #[DataProvider('sendAndTest')]
    public function test_send_trashedCampaignUnknown(string $action, array $fields): void
    {
        $this->draftOfPage(active: false);
        self::post($action, over: $fields);

        self::assertEquals(new ActionResult($action, ActionResult::REFUSED, Actions::UNKNOWN, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([[], []], [FakeActions::$queued, FakeActions::$tested]);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('sendAndTest')]
    public function test_send_campaignOfOtherSliceUnknown(string $action, array $fields): void
    {
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(Sending::MESSAGE)];
        self::post($action, over: $fields);

        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[], []], [FakeActions::$queued, FakeActions::$tested]);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('sendAndTest')]
    public function test_send_uidFromLoginNotRequest(string $action, array $fields): void
    {
        $this->draftOfPage();
        FakeActions::$queue = static fn(): SendStart => SendStart::refused(SendStart::FORBIDDEN);
        FakeActions::$test  = static fn(): TestResult => TestResult::refused(TestResult::FORBIDDEN);
        self::post($action, over: $fields + ['uid' => Sending::USER, 'isds_uid' => Sending::USER]);

        self::assertSame(Actions::FORBIDDEN, $this->actions(Sending::READER)->run(self::page(), true)?->code);
        self::assertSame([Sending::READER], array_column([...FakeActions::$queued, ...FakeActions::$tested], 1));
    }

    /** the real core: the campaign a draft to two cities with a box, ISDS telling the login is the sender's */
    private function realQueue(): TestSendQueue
    {
        TableDb::$engines += ['item' => 'InnoDB', 'content' => 'InnoDB'];
        $this->uploads = sys_get_temp_dir() . '/isds-actions-' . bin2hex(random_bytes(6));
        mkdir($this->uploads);
        file_put_contents("$this->uploads/pozvanka.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $queue = new TestSendQueue(
            FakeSliceRules::ready(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE)->withSource(Sending::SITE, Sending::SOURCE),
            new FakeRecipients([
                'a0000000000000000000000000000001' => Sending::item('Adamov', 'mesto', '0', 'aaa0001'),
                'c0000000000000000000000000000003' => Sending::item('Cheb', 'mesto', '0', 'ccc0003'),
            ]),
            $this->uploads,
        );
        $queue->files = [TestSendQueue::UPLOAD_URL . '/pozvanka.pdf'];
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'] = [
            Schema::CAMPAIGN_STATE    => Schema::CAMPAIGN_STATE_DRAFT,
            Schema::CAMPAIGN_SUBJECT  => 'Pozvánka',
            Schema::CAMPAIGN_BODY     => '<p>Zveme Vás</p>',
            Schema::CAMPAIGN_TO_HANDS => 'starosta',
            Schema::CAMPAIGN_FILTER   => Sending::filter(),
        ];
        TestSendRun::$http = new ScriptedIsds(['GetOwnerInfoFromLogin2' => [new Response(200, self::fixture('owner-info.xml'))]]);
        FakeActions::$queue = static fn(Setup $setup, string $campaign, string $uid, int $confirmed): SendStart => $queue->queue($setup, $campaign, $uid, $confirmed);

        return $queue;
    }

    // two tabs of one user - another person alike: one run; the tab whose request finds the campaign
    // locked is told it is busy, a later one that the campaign is queued already
    public function test_send_twoTabsOneRun(): void
    {
        $this->draftOfPage();
        $queue = $this->realQueue();
        $page  = Setup::fromSnapshot(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE, Sending::SOURCE, ['mesto' => 'Cities'], ['mesto'], Sending::MEMBER);
        $tab   = function () use ($page): ?string {
            Actions::forget();
            self::post('send', $page, over: ['isds_confirm' => '2', 'isds_form' => bin2hex(random_bytes(8))]);

            return $this->actions()->run($page, true)?->code;
        };

        $queue->lockedByOther = true;
        self::assertSame(Actions::BUSY, $tab(), 'while another request queues it');
        $queue->lockedByOther = false;
        self::assertSame(Actions::QUEUED, $tab());
        self::assertSame(SendStart::NOT_DRAFT, $tab(), 'queued since');
        self::assertCount(1, TestSendRun::recordsOfAll(['state']), 'one run');
    }

    public function test_test_boxFromPost(): void
    {
        $this->draftOfPage();
        FakeActions::$test = static fn(): TestResult => TestResult::refused(TestResult::NOT_TEST_BOX);
        self::post('test', over: ['isds_box' => 'ab12cde']);

        self::assertSame(TestResult::NOT_TEST_BOX, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER, 'ab12cde']], FakeActions::$tested);
    }

    // the core chooses the only test box; a box that is no text is no box
    public function test_test_noBoxPostedLeftToTheCore(): void
    {
        $this->draftOfPage();
        FakeActions::$test = static fn(): TestResult => TestResult::refused(TestResult::NOT_TEST_BOX);
        self::post('test', over: ['isds_box' => ['dj7j6mk']]);

        $this->actions()->run(self::page(), true);

        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER, '']], FakeActions::$tested);
    }

    /**
     * Each way a test ends, as the page tells it after the redirect: codes and ids, the words of ISDS
     * only where AA has no text of its status
     * @return array<string, array{\Closure(): TestResult, ActionResult}>
     */
    public static function endingsOfATest(): array
    {
        $of = static fn(SendResult|\Throwable $result, ?bool $charges = false): \Closure => static fn(): TestResult => TestResult::of(Outcome::of($result), 'msg', 'dj7j6mk', '4711-T2', $charges, $charges);
        $c  = 'ca000000000000000000000000000c01';

        return [
            'sent'                   => [$of(new SendResult('1835603', '0000', 'OK')), new ActionResult('test', ActionResult::DONE, Actions::TEST_SENT, $c, params: ['1835603', '4711-T2'])],
            'sent, paid'             => [$of(new SendResult('1835603', '0000', 'OK'), true), new ActionResult('test', ActionResult::DONE, Actions::TEST_SENT, $c, params: ['1835603', '4711-T2'], charge: TestResult::CHARGED)],
            'uncertain'              => [$of(new \RuntimeException('the answer got lost')), new ActionResult('test', ActionResult::DONE, Actions::TEST_UNCERTAIN, $c, params: ['4711-T2'])],
            'uncertain, price unknown' => [$of(new \RuntimeException('the answer got lost'), null), new ActionResult('test', ActionResult::DONE, Actions::TEST_UNCERTAIN, $c, params: ['4711-T2'], charge: TestResult::MAY_BE_CHARGED)],
            'refused, status known'  => [$of(new IsdsException('Schránka přijímá jen PDZ', '1233'), true), new ActionResult('test', ActionResult::FAILED, Actions::TEST_FAILED, $c, params: ['1233'])],
            'refused, status unknown' => [$of(new IsdsException('Něco <divného>', '1214')), new ActionResult('test', ActionResult::FAILED, Actions::TEST_FAILED, $c, 'Něco <divného>', ['1214'])],
            // nothing sent: refused as when queueing, so the same form may try again
            'login refused'          => [static fn(): TestResult => TestResult::of(Outcome::of(new AuthException('refused')), '', 'dj7j6mk', '', null, false), new ActionResult('test', ActionResult::REFUSED, Outcome::LOGIN, $c)],
            'busy'                   => [static fn(): TestResult => TestResult::refused(TestResult::BUSY), new ActionResult('test', ActionResult::BUSY, Actions::TEST_BUSY, $c)],
            'the server'             => [static fn(): TestResult => TestResult::refused(SendStart::SERVER, 'There is no temporary directory for the PDF letter'), new ActionResult('test', ActionResult::REFUSED, SendStart::SERVER, $c)],
            'the same letter'        => [static fn(): TestResult => TestResult::refused(TestResult::SAME_LETTER, msg: 'msg', ref: '4711-T1', dmId: '1835600', at: 1_800_000_000), new ActionResult('test', ActionResult::REFUSED, TestResult::SAME_LETTER, $c, params: ['4711-T1', 1_800_000_000])],
            'paid unknown'           => [static fn(): TestResult => TestResult::refused(TestResult::PAID_UNKNOWN, isdsCode: '5001'), new ActionResult('test', ActionResult::REFUSED, TestResult::PAID_UNKNOWN, $c, params: ['5001'])],
            'maintenance'            => [static fn(): TestResult => TestResult::refused(TestResult::MAINTENANCE, '1800003600'), new ActionResult('test', ActionResult::REFUSED, TestResult::MAINTENANCE, $c, params: [1_800_003_600])],
            'content'                => [static fn(): TestResult => TestResult::refused(SendStart::CONTENT, 'The subject is longer than 255 characters'), new ActionResult('test', ActionResult::REFUSED, SendStart::CONTENT, $c, 'The subject is longer than 255 characters')],
            'account'                => [static fn(): TestResult => TestResult::refused(SendStart::ACCOUNT, 'Set accounts[NSZM][login] in datovka_conf.php'), new ActionResult('test', ActionResult::REFUSED, SendStart::ACCOUNT, $c)],
            'not a draft'            => [static fn(): TestResult => TestResult::refused(TestResult::NOT_DRAFT), new ActionResult('test', ActionResult::REFUSED, TestResult::NOT_DRAFT, $c)],
        ];
    }

    /** @param \Closure(): TestResult $test */
    #[DataProvider('endingsOfATest')]
    public function test_test_resultOfEachStatus(\Closure $test, ActionResult $expected): void
    {
        $this->draftOfPage();
        FakeActions::$test = $test;
        self::post('test', over: ['isds_box' => 'dj7j6mk']);

        self::assertEquals($expected, $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$logged, 'the core logs the test');
    }

    // ------------------------------------------------------------ Save

    /** @param array{code: string, campaign: string, field: string, file: string, refiltered: bool} $told what the core says */
    private static function saves(array $told): void
    {
        FakeActions::$save = static fn(): array => $told;
    }

    // the form goes to the core as posted - it reads its own fields; an autosave every few seconds is not logged
    public function test_save_formToTheCore(): void
    {
        $this->draftOfPage();
        self::saves(['code' => Drafts::SAVED, 'campaign' => self::CAMPAIGN_ITEM, 'field' => '', 'file' => '', 'refiltered' => false]);
        self::post('save', over: ['isds_subject' => 'Výzva', 'isds_uid' => Sending::READER]);

        self::assertEquals(new ActionResult('save', ActionResult::DONE, Actions::SAVED, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([self::CAMPAIGN_ITEM, 'Výzva'], [FakeActions::$saved[0][0], FakeActions::$saved[0][1]['isds_subject']]);
        self::assertSame([[], []], [FakeActions::$logged, FakeActions::$failures]);
    }

    // the page learns the id of the new draft from the result: the browser is sent to it
    public function test_save_newDraftNamesItsId(): void
    {
        self::saves(['code' => Drafts::SAVED, 'campaign' => self::OTHER_ITEM, 'field' => '', 'file' => '', 'refiltered' => false]);
        self::post('save', campaign: '', over: ['isds_nonce' => str_repeat('a', 32)]);

        self::assertEquals(new ActionResult('save', ActionResult::DONE, Actions::SAVED, self::OTHER_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame('', FakeActions::$saved[0][0]);
    }

    // the page after the save keeps the page of a list only while its recipients are the same
    public function test_save_toldWhetherTheFilterChanged(): void
    {
        $this->draftOfPage();
        foreach ([Drafts::SAVED, Drafts::FILTER_CHANGED] as $code) {
            foreach ([false, true] as $refiltered) {
                Actions::forget();
                self::saves(['code' => $code, 'campaign' => self::CAMPAIGN_ITEM, 'field' => '', 'file' => '', 'refiltered' => $refiltered]);
                self::post('save');
                self::assertEquals(new ActionResult('save', ActionResult::DONE, $code, self::CAMPAIGN_ITEM, refiltered: $refiltered), $this->actions()->run(self::page(), true));
            }
        }
    }

    /** @return array<string, array{array{code: string, campaign: string, field: string, file: string, refiltered: bool}, ActionResult}> */
    public static function saveRefusals(): array
    {
        $told = static fn(string $code, string $campaign = self::CAMPAIGN_ITEM, string $field = ''): array => ['code' => $code, 'campaign' => $campaign, 'field' => $field, 'file' => '', 'refiltered' => false];

        return [
            'a field'          => [$told(Actions::INPUT, field: Drafts::SUBJECT), new ActionResult('save', ActionResult::REFUSED, Actions::INPUT, self::CAMPAIGN_ITEM, params: [Drafts::SUBJECT])],
            'the nonce'        => [$told(Actions::INPUT, ''), new ActionResult('save', ActionResult::REFUSED, Actions::INPUT)],
            'queued'           => [$told(SendStart::NOT_DRAFT), new ActionResult('save', ActionResult::REFUSED, SendStart::NOT_DRAFT, self::CAMPAIGN_ITEM)],
            'with a run'       => [$told(SendStart::ALREADY), new ActionResult('save', ActionResult::REFUSED, SendStart::ALREADY, self::CAMPAIGN_ITEM)],
            'being queued'     => [$told(SendStart::BUSY), new ActionResult('save', ActionResult::BUSY, SendStart::BUSY, self::CAMPAIGN_ITEM)],
            'trashed meanwhile' => [$told(SendStart::UNKNOWN, ''), new ActionResult('save', ActionResult::REFUSED, SendStart::UNKNOWN)],
        ];
    }

    // the files go to the core as PHP received them; a file refused is named by the result, never by its text
    public function test_save_filesToTheCoreRefusalNamesTheFile(): void
    {
        $this->draftOfPage();
        self::saves(['code' => Actions::INPUT, 'campaign' => self::CAMPAIGN_ITEM, 'field' => Drafts::FILE_TYPE, 'file' => 'logo.svg', 'refiltered' => false]);
        self::post('save');
        $_FILES = ['isds_files' => ['name' => ['logo.svg'], 'type' => ['image/svg+xml'], 'tmp_name' => ['/tmp/php1'], 'error' => [UPLOAD_ERR_OK], 'size' => [10]]];

        self::assertEquals(new ActionResult('save', ActionResult::REFUSED, Actions::INPUT, self::CAMPAIGN_ITEM, params: [Drafts::FILE_TYPE, 'logo.svg']), $this->actions()->run(self::page(), true));
        self::assertSame($_FILES, FakeActions::$saved[0][2]);
    }

    /** @param array{code: string, campaign: string, field: string, file: string, refiltered: bool} $told */
    #[DataProvider('saveRefusals')]
    public function test_save_refusal(array $told, ActionResult $expected): void
    {
        $this->draftOfPage();
        self::saves($told);
        self::post('save');

        self::assertEquals($expected, $this->actions()->run(self::page(), true));
    }

    // a draft in the trash, or an item of another slice, is no draft of the page to change: the page goes
    // on with no campaign, as when the core refuses it so
    public function test_save_trashedOrForeignCampaignUnknown(): void
    {
        $this->draftOfPage(active: false);
        self::post('save', over: ['isds_subject' => 'Výzva']);
        self::assertEquals(new ActionResult('save', ActionResult::REFUSED, Actions::UNKNOWN), $this->actions()->run(self::page(), true));

        Actions::forget();
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(Sending::MESSAGE)];
        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([], FakeActions::$saved);
    }

    // the token of a new draft is no token of a draft of the page, nor the other way round
    public function test_save_tokenOfNewDraftActsOnNoCampaign(): void
    {
        $this->draftOfPage();
        self::post('save', campaign: '', over: ['isds_campaign' => self::CAMPAIGN_ITEM]);

        self::assertExpired($this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$saved);
    }

    // the real core: a form of a new draft saved twice - an autosave - makes one item, never a second
    public function test_save_realDraftsOneItemForTheForm(): void
    {
        $drafts = new MemoryDrafts();
        FakeActions::$save = static fn(Setup $setup, string $campaign, array $post, array $files): array => $drafts->save($setup, $campaign, $post, $files);
        $nonce  = str_repeat('b', 32);

        self::post('save', campaign: '', over: ['isds_nonce' => $nonce, 'isds_subject' => 'Výzva']);
        $first = $this->actions()->run(self::page(), true);
        Actions::forget();
        self::post('save', campaign: '', over: ['isds_nonce' => $nonce, 'isds_subject' => 'Výzva 2']);
        $second = $this->actions()->run(self::page(), true);

        self::assertSame([Actions::SAVED, Actions::SAVED], [$first?->code, $second?->code]);
        self::assertSame(Drafts::newId(self::page(), $nonce), $first?->campaign);
        self::assertSame($first?->campaign, $second?->campaign);
        self::assertSame([$first?->campaign], array_keys($drafts->items));
        self::assertSame('Výzva 2', $drafts->field((string) $first?->campaign, Schema::CAMPAIGN_SUBJECT));
    }

    // ------------------------------------------------------------ Discard

    /** the real core over a draft of the page in memory, $before done to it first */
    private function discardsInMemory(?\Closure $before = null): MemoryDrafts
    {
        $this->draftOfPage();
        $drafts = new MemoryDrafts();
        $drafts->draft(self::CAMPAIGN_ITEM);
        if ($before) {
            $before($drafts);
        }
        FakeActions::$discard = static fn(Setup $setup, string $campaign): string => $drafts->trash($setup, $campaign);

        return $drafts;
    }

    // the page goes on with no campaign; the editor finds the draft in the trash of the slice
    public function test_discard_draftTrashedAndLogged(): void
    {
        $drafts = $this->discardsInMemory();
        self::post('discard', over: ['isds_uid' => Sending::READER]);

        self::assertEquals(new ActionResult('discard', ActionResult::DONE, Actions::DISCARDED), $this->actions()->run(self::page(), true));
        self::assertSame('3', $drafts->field(self::CAMPAIGN_ITEM, 'status_code.....'));
        self::assertSame([[SendQueue::LOG_EVENT, 'discard:' . self::CAMPAIGN_ITEM, [Sending::USER]]], FakeActions::$logged);
    }

    // sending lets a user change drafts; the trash takes the right the Item Manager asks for it
    public function test_discard_withoutTheRightToTrashForbidden(): void
    {
        $drafts = $this->discardsInMemory();
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => PS_EDIT_ALL_ITEMS]]);
        self::post('discard');

        self::assertEquals(new ActionResult('discard', ActionResult::REFUSED, Actions::FORBIDDEN, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([[], [], []], [FakeActions::$discarded, $drafts->writes, FakeActions::$logged]);
    }

    /** @return array<string, array{\Closure(MemoryDrafts): void, string}> a campaign of the page that is no draft to discard, what the core says of it */
    public static function notDiscardable(): array
    {
        return [
            'with a run'         => [static fn(MemoryDrafts $d) => $d->runs = [self::CAMPAIGN_ITEM], SendStart::ALREADY],
            'sent'               => [static fn(MemoryDrafts $d) => $d->draft(self::CAMPAIGN_ITEM, state: Schema::CAMPAIGN_STATE_DONE), SendStart::NOT_DRAFT],
            'stopped, no run'    => [static fn(MemoryDrafts $d) => $d->draft(self::CAMPAIGN_ITEM, state: Schema::CAMPAIGN_STATE_STOPPED), SendStart::NOT_DRAFT],
        ];
    }

    // a campaign once queued keeps its sending in view: nothing moved, nothing logged, the page shows it on -
    // told in words of its own, not as a save refused (no copy to make)
    #[DataProvider('notDiscardable')]
    public function test_discard_campaignWithSendingRefused(\Closure $sending, string $core): void
    {
        $drafts = $this->discardsInMemory($sending);
        $before = $drafts->items;
        self::post('discard');

        self::assertEquals(new ActionResult('discard', ActionResult::REFUSED, Actions::NOT_DISCARDABLE, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertNotSame(Advice::text($core), Advice::text(Actions::NOT_DISCARDABLE));
        self::assertSame([[], $before, []], [$drafts->writes, $drafts->items, FakeActions::$logged]);
    }

    public function test_discard_busyWhileQueued(): void
    {
        $drafts                = $this->discardsInMemory();
        $drafts->lockedByOther = true;
        self::post('discard');

        self::assertEquals(new ActionResult('discard', ActionResult::BUSY, SendStart::BUSY, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([[], []], [$drafts->writes, FakeActions::$logged]);
    }

    /** @return array<string, array{array<string, array{slice: string, active: bool, filter: string, state: string}>}> what the gate reads of the campaign posted */
    public static function notDraftsOfThePage(): array
    {
        $draft = ['slice' => Sending::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'draft'];

        return [
            'in the trash'     => [[self::CAMPAIGN_ITEM => ['active' => false] + $draft]],
            'of another slice' => [[self::CAMPAIGN_ITEM => ['slice' => Sending::MESSAGE] + $draft]],
            'no such item'     => [[]],
        ];
    }

    // no campaign of the page to discard: the core is not asked, the page goes on with none
    /** @param array<string, array{slice: string, active: bool, filter: string, state: string}> $campaigns */
    #[DataProvider('notDraftsOfThePage')]
    public function test_discard_notADraftOfThePageUnknown(array $campaigns): void
    {
        $this->campaigns = $campaigns;
        self::post('discard');

        self::assertEquals(new ActionResult('discard', ActionResult::REFUSED, Actions::UNKNOWN), $this->actions()->run(self::page(), true));
        self::assertSame([[], []], [FakeActions::$discarded, FakeActions::$logged]);
    }

    // the core finds it gone meanwhile - in the trash already, by another window: told as above
    public function test_discard_trashedMeanwhileUnknown(): void
    {
        $drafts = $this->discardsInMemory(static fn(MemoryDrafts $d) => $d->draft(self::CAMPAIGN_ITEM, status: 3));
        self::post('discard');

        self::assertEquals(new ActionResult('discard', ActionResult::REFUSED, Actions::UNKNOWN), $this->actions()->run(self::page(), true));
        self::assertSame([[], []], [$drafts->writes, FakeActions::$logged]);
    }

    // a double click: the first result, the draft not moved nor logged again - past the gate too
    public function test_discard_twiceMovesAndLogsOnce(): void
    {
        $drafts = $this->discardsInMemory();
        self::post('discard');
        $first = $this->actions()->run(self::page(), true);

        Actions::forget();
        self::assertSame($first, $this->actions(answered: static fn(): ActionResult => $first)->run(self::page(), true), 'what the first did');
        Actions::forget();
        $this->campaigns[self::CAMPAIGN_ITEM]['active'] = false;
        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code, 'the result kept gone: in the trash now');

        self::assertSame([[self::CAMPAIGN_ITEM], 1, 1], [FakeActions::$discarded, count($drafts->writes), count(FakeActions::$logged)]);
    }

    /** @return array<string, array{string, string}> the purpose a token was given for: action, campaign */
    public static function otherForms(): array
    {
        return [
            'of Save'           => ['save', self::CAMPAIGN_ITEM],
            'of Copy'           => ['clone', self::CAMPAIGN_ITEM],
            'of another draft'  => ['discard', self::OTHER_ITEM],
            'of a new draft'    => ['save', ''],
        ];
    }

    #[DataProvider('otherForms')]
    public function test_discard_tokenOfAnotherFormExpired(string $action, string $campaign): void
    {
        $this->discardsInMemory();
        self::post('discard', over: ['isds_token' => FormToken::get(Gate::purpose($action, self::page(), $campaign))]);

        self::assertExpired($this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$discarded);
    }

    // ------------------------------------------------------------ Copy

    /** the source item of recipient $i of a run queued(): the item the copy of the unsent goes to */
    private static function source(int $i): string
    {
        return sprintf('b%031x', $i);
    }

    /** the core copies: CLONED, a copy of the id $copy made now */
    private static function copies(string $copy = self::OTHER_ITEM, bool $made = true): void
    {
        FakeActions::$copy = static fn(): array => ['code' => Drafts::CLONED, 'campaign' => $copy, 'made' => $made];
    }

    /** @param array<string, mixed> $over */
    private static function postCopy(string $scope, array $over = []): void
    {
        self::post('clone', over: ['isds_scope' => $scope, 'isds_nonce' => str_repeat('c', 32)] + $over);
    }

    /**
     * A sending a person stopped once 2 of 5 messages were tried, then followed the delivery of the one
     * sent: recipients 2, 3 and 4 never tried
     * @return array<string, string>
     */
    private static function stoppedWatching(): array
    {
        return ['dm_ids' => '{"0":"1001"}', 'failed' => '{"1":"1233"}', 'cursor' => '2', 'stop_by' => Sending::USER, 'watch_from' => (string) (self::NOW - 3600)];
    }

    /**
     * The states of a campaign as its page shows them, and what a copy of the unsent does in each
     * @return array<string, array{?\Closure(): string, string, list<int>}> the run (null = none), the code,
     *         the recipients the copy goes to
     */
    public static function cloneStates(): array
    {
        $run     = static fn(array $props, string $state = SendRun::SENDING, int $n = 5): \Closure => static function () use ($props, $state, $n): string {
            $id = self::queued($n, $props);
            if ($state !== SendRun::SENDING) {
                self::leftInError($id, state: $state);
            }
            return $id;
        };
        $stopped = ['dm_ids' => '{"0":"1001"}', 'cursor' => '1', 'stop_by' => Sending::USER];
        $watch   = ['watch_from' => (string) (self::NOW - 3600)];

        return [
            'draft: no run'                  => [null, Actions::WRONG_STATE, []],
            'sending'                        => [$run(['dm_ids' => '{"0":"1001"}', 'cursor' => '1']), Actions::WRONG_STATE, []],
            'error'                          => [$run(['dm_ids' => '{"0":"1001"}', 'cursor' => '1'], SendRun::ERROR), Actions::WRONG_STATE, []],
            // nothing sends one never tried once a person stopped the sending, whatever its step does now
            'sending, stopped'               => [$run($stopped), Actions::CLONED, [1, 2, 3, 4]],
            'error, stopped'                 => [$run($stopped, SendRun::ERROR), Actions::CLONED, [1, 2, 3, 4]],
            'watching, stopped'              => [$run($stopped + $watch), Actions::CLONED, [1, 2, 3, 4]],
            'watching, every message tried'  => [$run(self::watched(5)), Actions::NONE_STOPPED, []],
            'watch_error, stopped'           => [$run($stopped + $watch, SendRun::ERROR), Actions::CLONED, [1, 2, 3, 4]],
            'done, stopped'                  => [$run($stopped, SendRun::DONE), Actions::CLONED, [1, 2, 3, 4]],
            'done, every message tried'      => [$run(self::tried(5), SendRun::DONE), Actions::NONE_STOPPED, []],
            'abandoned'                      => [$run(['dm_ids' => '{"0":"1001"}', 'cursor' => '1'], SendRun::ENDED), Actions::CLONED, [1, 2, 3, 4]],
        ];
    }

    /**
     * @param ?\Closure(): string $run
     * @param list<int>            $unsent
     */
    #[DataProvider('cloneStates')]
    public function test_clone_unsentByState(?\Closure $run, string $code, array $unsent): void
    {
        $run && $run();
        self::copies();
        self::postCopy(Drafts::UNSENT);

        $result = $this->actions()->run(self::page(), true);

        $cloned = $code === Actions::CLONED;
        self::assertEquals($cloned ? new ActionResult('clone', ActionResult::DONE, Actions::CLONED, self::OTHER_ITEM) : new ActionResult('clone', ActionResult::REFUSED, $code, self::CAMPAIGN_ITEM), $result);
        self::assertSame($cloned ? [[self::CAMPAIGN_ITEM, Drafts::UNSENT, array_map(self::source(...), $unsent)]] : [], FakeActions::$copied);
        self::assertSame($cloned ? [[SendQueue::LOG_EVENT, 'clone:' . self::OTHER_ITEM, [self::CAMPAIGN_ITEM, Drafts::UNSENT, Sending::USER]]] : [], FakeActions::$logged);
    }

    // never one that may have gone, failed or was skipped for its box or its reason: only those never tried
    public function test_clone_unsentOnlyStopped(): void
    {
        self::queued(7, ['uncertain' => '{"2":' . (self::NOW - 600) . '}', 'skips' => '{"3":"inactive"}', 'cursor' => '5'] + self::stoppedWatching(), reasons: [4 => 'duplicate']);
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertSame(Actions::CLONED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Drafts::UNSENT, [self::source(5), self::source(6)]]], FakeActions::$copied);
    }

    // while it sends, those not tried yet may still go: a copy now could send a recipient twice
    public function test_clone_unsentWhileSendingWrongState(): void
    {
        $id = self::queued(5, ['dm_ids' => '{"0":"1001"}', 'cursor' => '1']);
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertSame(Actions::WRONG_STATE, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[], [], SendRun::SENDING], [FakeActions::$copied, FakeActions::$logged, self::stored($id)['state']]);
    }

    // stopped while a message was on its way: that one may still go, so the copy never takes it
    public function test_clone_unsentOfStoppedSendingLeavesInflight(): void
    {
        self::queued(5, ['dm_ids' => '{"0":"1001"}', 'inflight' => '1', 'inflight_at' => (string) (self::NOW - 5), 'cursor' => '2', 'stop_by' => Sending::USER]);
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertSame(Actions::CLONED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Drafts::UNSENT, [self::source(2), self::source(3), self::source(4)]]], FakeActions::$copied);
    }

    // given up: what the record says, never the items - the one in flight may have gone though its item says stopped
    public function test_clone_unsentAbandonedFromRecord(): void
    {
        $id = self::leftInError(self::queued(5, ['dm_ids' => '{"0":"1001"}', 'inflight' => '1', 'inflight_at' => (string) (self::NOW - 600), 'cursor' => '2']), state: SendRun::ENDED);
        self::items()->mark(self::message($id, 1), Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::NOW);
        self::items()->mark(self::message($id, 3), Schema::MESSAGE_STATE_SENT, Schema::EVENT_STOPPED, self::NOW);
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertSame(Actions::CLONED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Drafts::UNSENT, [self::source(2), self::source(3), self::source(4)]]], FakeActions::$copied);
    }

    public function test_clone_unsentNoneStopped(): void
    {
        self::queued(3, self::watched(3));
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertEquals(new ActionResult('clone', ActionResult::REFUSED, Actions::NONE_STOPPED, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([[], []], [FakeActions::$copied, FakeActions::$logged]);
    }

    // a campaign put in the trash still shows its sending, and those it left unsent may still be sent
    public function test_clone_unsentOfTrashedCampaign(): void
    {
        self::queued(5, self::stoppedWatching());
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertSame(Actions::CLONED, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([[self::CAMPAIGN_ITEM, Drafts::UNSENT, [self::source(2), self::source(3), self::source(4)]]], FakeActions::$copied);
    }

    /** @param array<string, string> $props */
    #[DataProvider('runOfOtherBinding')]
    public function test_clone_unsentOfRunOfOtherBindingUnknown(array $props): void
    {
        self::queued(5, self::stoppedWatching() + $props);
        self::copies();
        self::postCopy(Drafts::UNSENT);

        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([], FakeActions::$copied);
    }

    /** @return array<string, array{string, bool}> the state of the campaign item, whether it has a run */
    public static function anyState(): array
    {
        return ['draft' => [Schema::CAMPAIGN_STATE_DRAFT, false], 'draft with a run' => [Schema::CAMPAIGN_STATE_DRAFT, true], 'sending' => [Schema::CAMPAIGN_STATE_SENDING, true], 'done' => [Schema::CAMPAIGN_STATE_DONE, true]];
    }

    // the whole letter is copied whatever the campaign went through; the page goes on with the copy
    #[DataProvider('anyState')]
    public function test_clone_allOfAnyState(string $state, bool $run): void
    {
        $run && self::queued(3);
        $this->campaigns = [self::CAMPAIGN_ITEM => ['state' => $state] + self::item()];
        self::copies();
        self::postCopy(Drafts::ALL, ['isds_uid' => Sending::READER]);

        self::assertEquals(new ActionResult('clone', ActionResult::DONE, Actions::CLONED, self::OTHER_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([[self::CAMPAIGN_ITEM, Drafts::ALL, []]], FakeActions::$copied);
        self::assertSame([[SendQueue::LOG_EVENT, 'clone:' . self::OTHER_ITEM, [self::CAMPAIGN_ITEM, Drafts::ALL, Sending::USER]]], FakeActions::$logged);
    }

    // a campaign in the trash is no letter of the page to copy whole
    public function test_clone_allOfTrashedCampaignUnknown(): void
    {
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::copies();
        self::postCopy(Drafts::ALL);

        self::assertEquals(new ActionResult('clone', ActionResult::REFUSED, Actions::UNKNOWN), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$copied);
    }

    /** @return array<string, array{mixed}> */
    public static function badScopes(): array
    {
        return ['none' => [null], 'another' => ['some'], 'one with its message' => [Drafts::ONE . ':a0000000000000000000000000000001'], 'a list' => [['all']]];
    }

    #[DataProvider('badScopes')]
    public function test_clone_badScopeIsInput(mixed $scope): void
    {
        self::copies();
        self::post('clone', over: ['isds_scope' => $scope, 'isds_nonce' => str_repeat('c', 32)]);

        self::assertEquals(new ActionResult('clone', ActionResult::REFUSED, Actions::INPUT, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$copied);
    }

    /** @return array<string, array{array{code: string, campaign: string, made: bool}, ActionResult}> */
    public static function copyRefusals(): array
    {
        return [
            'the nonce'           => [['code' => Actions::INPUT, 'campaign' => '', 'made' => false], new ActionResult('clone', ActionResult::REFUSED, Actions::INPUT, self::CAMPAIGN_ITEM)],
            'no session'          => [['code' => Actions::EXPIRED, 'campaign' => '', 'made' => false], new ActionResult('clone', ActionResult::REFUSED, Actions::EXPIRED, self::CAMPAIGN_ITEM)],
            'being copied'        => [['code' => SendStart::BUSY, 'campaign' => self::OTHER_ITEM, 'made' => false], new ActionResult('clone', ActionResult::BUSY, SendStart::BUSY, self::OTHER_ITEM)],
            'the copy queued'     => [['code' => SendStart::NOT_DRAFT, 'campaign' => self::OTHER_ITEM, 'made' => false], new ActionResult('clone', ActionResult::REFUSED, SendStart::NOT_DRAFT, self::OTHER_ITEM)],
            'not its own copy'    => [['code' => SendStart::UNKNOWN, 'campaign' => '', 'made' => false], new ActionResult('clone', ActionResult::REFUSED, Actions::UNKNOWN)],
        ];
    }

    /** @param array{code: string, campaign: string, made: bool} $told */
    #[DataProvider('copyRefusals')]
    public function test_clone_refusal(array $told, ActionResult $expected): void
    {
        FakeActions::$copy = static fn(): array => $told;
        self::postCopy(Drafts::ALL);

        self::assertEquals($expected, $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$logged);
    }

    // the real core: the same form posted twice - a double click past the gate - makes one copy, logged once
    public function test_clone_realDraftsOneCopyLoggedOnce(): void
    {
        $drafts = new MemoryDrafts();
        $drafts->draft(self::CAMPAIGN_ITEM, state: Schema::CAMPAIGN_STATE_DONE);
        FakeActions::$copy = static fn(Setup $setup, string $source, string $scope, array $post, array $unsent): array => $drafts->copy($setup, $source, $scope, $post, $unsent);

        self::postCopy(Drafts::ALL);
        $first = $this->actions()->run(self::page(), true);
        Actions::forget();
        $second = $this->actions()->run(self::page(), true);

        $copy = Drafts::copyId(self::page(), self::CAMPAIGN_ITEM, Drafts::ALL, str_repeat('c', 32));
        self::assertEquals([new ActionResult('clone', ActionResult::DONE, Actions::CLONED, $copy)], array_values(array_unique([$first, $second], SORT_REGULAR)));
        self::assertSame([self::CAMPAIGN_ITEM, $copy], array_keys($drafts->items));
        self::assertSame([[SendQueue::LOG_EVENT, "clone:$copy", [self::CAMPAIGN_ITEM, Drafts::ALL, Sending::USER]]], FakeActions::$logged);
    }

    /** Copy to the recipient of message $i of the run $id - the message as posted, when $msg is not null */
    private static function postOne(string $id, int $i, mixed $msg = null): void
    {
        self::postCopy(Drafts::ONE, ['isds_message' => $msg ?? self::message($id, $i)]);
    }

    /**
     * Copied to recipient $i of the run $id alone, logged; or refused with $code, nothing asked of the core
     * nor logged
     */
    private function assertCopiedOne(string $id, int $i, string $code = Actions::CLONED): void
    {
        $result = $this->actions()->run(self::page(), true);

        $scope = Drafts::one(self::message($id, $i));
        if ($code === Actions::CLONED) {
            self::assertEquals(new ActionResult('clone', ActionResult::DONE, Actions::CLONED, self::OTHER_ITEM), $result);
            self::assertSame([[self::CAMPAIGN_ITEM, $scope, [self::source($i)]]], FakeActions::$copied);
            self::assertSame([[SendQueue::LOG_EVENT, 'clone:' . self::OTHER_ITEM, [self::CAMPAIGN_ITEM, $scope, Sending::USER]]], FakeActions::$logged);
        } else {
            self::assertEquals(new ActionResult('clone', ActionResult::REFUSED, $code, self::CAMPAIGN_ITEM), $result);
            self::assertSame([[], []], [FakeActions::$copied, FakeActions::$logged]);
        }
    }

    /**
     * The fates of recipients of 5 and whether the letter may go to one again
     * @return array<string, array{array<string, string>, string, int, string}> props of the run, its state, the recipient, the code
     */
    public static function failedOrStopped(): array
    {
        $t        = self::NOW - 600;
        $followed = [
            'dm_ids'     => '{"0":"1001","1":"1002","2":"1003"}',
            'dm_state'   => json_encode(['0' => "3:$t:0:0", '1' => "8:$t:$t:0", '2' => "4:$t:$t:0"], JSON_FORCE_OBJECT),
            'cursor'     => '3',
            'watch_from' => (string) (self::NOW - 3600),
        ];
        $once     = ['dm_ids' => '{"0":"1001"}', 'cursor' => '1'];

        return [
            'refused, the sending stopped'          => [self::stoppedWatching(), SendRun::SENDING, 1, Actions::CLONED],
            'never tried, the sending stopped'      => [self::stoppedWatching(), SendRun::SENDING, 3, Actions::CLONED],
            'sent, the sending stopped'             => [self::stoppedWatching(), SendRun::SENDING, 0, Actions::NOT_RESENDABLE],
            // a refusal is final while the sending goes on too
            'refused while it sends'                => [['dm_ids' => '{"0":"1001"}', 'failed' => '{"1":"1233"}', 'cursor' => '2'], SendRun::SENDING, 1, Actions::CLONED],
            'sent, refused by the antivirus check'  => [$followed, SendRun::SENDING, 0, Actions::CLONED],
            'sent, the box made inaccessible'       => [$followed, SendRun::SENDING, 1, Actions::CLONED],
            'sent and delivered'                    => [$followed, SendRun::SENDING, 2, Actions::NOT_RESENDABLE],
            'never tried, the sending stopped, done' => [$once + ['stop_by' => Sending::USER], SendRun::DONE, 3, Actions::CLONED],
            'never tried, the sending given up'     => [$once, SendRun::ENDED, 3, Actions::CLONED],
        ];
    }

    // only a fate no step changes any more: failed, or never tried by a sending nothing sends on
    /** @param array<string, string> $props */
    #[DataProvider('failedOrStopped')]
    public function test_clone_oneFailedOrStoppedOnly(array $props, string $state, int $i, string $code): void
    {
        $id = self::queued(5, $props);
        if ($state !== SendRun::SENDING) {
            self::leftInError($id, state: $state);
        }
        self::copies();
        self::postOne($id, $i);

        $this->assertCopiedOne($id, $i, $code);
    }

    /** @return array<string, array{int}> */
    public static function verifiedAndNotfound(): array
    {
        return ['verified by a person' => [1], 'not found by the lookup' => [2]];
    }

    // a message of unknown fate found not sent - by a person or by the lookup - failed: it may go again
    #[DataProvider('verifiedAndNotfound')]
    public function test_clone_oneVerifiedAndNotfoundAreFailed(int $i): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"1001"}', 'failed' => '{"1":"' . Schema::VERIFIED . '","2":"' . Schema::NOT_FOUND . '"}', 'cursor' => '3'] + self::stoppedWatching());
        self::copies();
        self::postOne($id, $i);

        $this->assertCopiedOne($id, $i);
    }

    /**
     * Messages whose recipient may get the letter, or was left out
     * @return array<string, array{array<string, string>, array<int, string>, int}> props of the run, frozen reasons, the recipient
     */
    public static function notResendable(): array
    {
        $sending = ['dm_ids' => '{"0":"1001"}', 'cursor' => '1'];

        return [
            'queued: the sending goes on'  => [$sending, [], 3],
            'uncertain'                    => [['uncertain' => '{"2":' . (self::NOW - 600) . '}', 'cursor' => '5'] + self::stoppedWatching(), [], 2],
            'in flight, the sending stopped' => [['inflight' => '1', 'inflight_at' => (string) (self::NOW - 5), 'cursor' => '1', 'stop_by' => Sending::USER], [], 1],
            'skipped for its box'          => [['skips' => '{"3":"inactive"}', 'cursor' => '5'] + self::stoppedWatching(), [], 3],
            'skipped for its reason'       => [self::stoppedWatching(), [4 => 'duplicate'], 4],
            'sent'                         => [self::tried(5), [], 2],
        ];
    }

    /**
     * @param array<string, string> $props
     * @param array<int, string>    $reasons
     */
    #[DataProvider('notResendable')]
    public function test_clone_oneSentUncertainQueuedSkippedRefused(array $props, array $reasons, int $i): void
    {
        $id = self::queued(5, $props, reasons: $reasons);
        self::copies();
        self::postOne($id, $i);

        $this->assertCopiedOne($id, $i, Actions::NOT_RESENDABLE);
    }

    // a campaign never sent has no message to send again
    public function test_clone_oneNeverQueuedWrongState(): void
    {
        self::copies();
        self::postCopy(Drafts::ONE, ['isds_message' => 'a0000000000000000000000000000001']);

        self::assertEquals(new ActionResult('clone', ActionResult::REFUSED, Actions::WRONG_STATE, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$copied);
    }

    // the record and the recipients frozen decide, never what a message item shows now
    public function test_clone_oneFromSnapshotByMsg(): void
    {
        $id = self::queued(5, self::stoppedWatching());
        self::items()->mark(self::message($id, 3), Schema::MESSAGE_STATE_SENT, Schema::EVENT_STOPPED, self::NOW);
        self::items()->mark(self::message($id, 0), Schema::MESSAGE_STATE_FAILED, Schema::EVENT_STOPPED, self::NOW);
        self::copies();
        self::postOne($id, 3, strtoupper(self::message($id, 3)));

        $this->assertCopiedOne($id, 3);

        FakeActions::reset();
        self::copies();
        self::postOne($id, 0);
        $this->assertCopiedOne($id, 0, Actions::NOT_RESENDABLE);
    }

    /** @return array<string, array{mixed}> */
    public static function notAMessageOfTheSending(): array
    {
        return ['none' => [null], 'no id' => ['4711-2'], 'an array' => [['a0000000000000000000000000000001']], 'of no message' => ['a0000000000000000000000000000001'], 'the campaign' => [self::CAMPAIGN_ITEM]];
    }

    #[DataProvider('notAMessageOfTheSending')]
    public function test_clone_oneNotAMessageUnknownMessage(mixed $msg): void
    {
        self::queued(5, self::stoppedWatching());
        self::copies();
        self::postCopy(Drafts::ONE, ['isds_message' => $msg]);

        self::assertEquals(new ActionResult('clone', ActionResult::REFUSED, Actions::UNKNOWN_MESSAGE, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(), true));
        self::assertSame([], FakeActions::$copied);
    }

    /** @param array<string, string> $props */
    #[DataProvider('runOfOtherBinding')]
    public function test_clone_oneOtherBindingUnknown(array $props): void
    {
        $id = self::queued(5, self::stoppedWatching() + $props);
        self::copies();
        self::postOne($id, 3);

        self::assertSame(Actions::UNKNOWN, $this->actions()->run(self::page(), true)?->code);
        self::assertSame([], FakeActions::$copied);
    }

    // a campaign put in the trash still shows its sending, and its recipients that failed may still be sent
    public function test_clone_oneOfTrashedCampaign(): void
    {
        $id              = self::queued(5, self::stoppedWatching());
        $this->campaigns = [self::CAMPAIGN_ITEM => self::item(active: false)];
        self::copies();
        self::postOne($id, 1);

        $this->assertCopiedOne($id, 1);
    }

    // the real core: the same form twice makes one copy, logged once; the same form for another message another
    public function test_clone_oneDoubleClickOneCopy(): void
    {
        $id     = self::queued(5, self::stoppedWatching());
        $drafts = new MemoryDrafts();
        $drafts->draft(self::CAMPAIGN_ITEM, state: Schema::CAMPAIGN_STATE_DONE);
        FakeActions::$copy = static fn(Setup $setup, string $source, string $scope, array $post, array $items): array => $drafts->copy($setup, $source, $scope, $post, $items);

        $results = [];
        foreach ([1, 1, 3] as $i) {
            Actions::forget();
            self::postOne($id, $i);
            $results[] = $this->actions()->run(self::page(), true);
        }

        $nonce  = str_repeat('c', 32);
        $copies = array_map(static fn(int $i): string => Drafts::copyId(self::page(), self::CAMPAIGN_ITEM, Drafts::one(self::message($id, $i)), $nonce), [1, 3]);
        self::assertEquals([new ActionResult('clone', ActionResult::DONE, Actions::CLONED, $copies[0]), new ActionResult('clone', ActionResult::DONE, Actions::CLONED, $copies[0]), new ActionResult('clone', ActionResult::DONE, Actions::CLONED, $copies[1])], $results);
        self::assertSame([self::CAMPAIGN_ITEM, ...$copies], array_keys($drafts->items));
        self::assertSame([[self::source(1)], [self::source(3)]], array_map(static fn(string $copy): array => RecipientFilter::fromJson($drafts->field($copy, Schema::CAMPAIGN_FILTER), self::page())->include, $copies));
        self::assertSame(['clone:' . $copies[0], 'clone:' . $copies[1]], array_column(FakeActions::$logged, 1));
    }

    // ------------------------------------------------------------ Fix box

    /** Fix box of the recipient posted from the page in $environment: the item, the box it showed */
    private static function postFix(string $environment = Account::PRODUCTION, string $campaign = self::CAMPAIGN_ITEM, string $recipient = self::RECIPIENT, string $box = '7C6BY6U'): void
    {
        self::post('fixbox', self::page($environment), $campaign, ['isds_recipient' => $recipient, 'isds_box' => $box]);
    }

    /** @return array<string, array{string}> */
    public static function campaignsOfTestPage(): array
    {
        return ['of the page' => [self::CAMPAIGN_ITEM], 'of no page' => [self::OTHER_ITEM]];
    }

    // the source slice of a test page is that of production: a test writes no recipient, whatever it posts
    #[DataProvider('campaignsOfTestPage')]
    public function test_fixbox_neverInTestEnvironment(string $campaign): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::FIXED, 'old' => '', 'new' => '7c6by6u'];
        self::postFix(Account::TEST, $campaign);

        self::assertEquals(new ActionResult('fixbox', ActionResult::REFUSED, Actions::TEST_ENV, $campaign), $this->actions()->run(self::page(Account::TEST), true));
        self::assertSame([[], []], [FakeActions::$fixed, FakeActions::$logged], 'nothing asked of the core');
    }

    public function test_fixbox_logsOldAndNewUnderIsdsReg(): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::FIXED, 'old' => " x2pbqzq\n", 'new' => '7c6by6u'];
        self::postFix(recipient: strtoupper(self::RECIPIENT));
        $_POST['uid'] = Sending::READER;

        self::assertEquals(new ActionResult('fixbox', ActionResult::DONE, Actions::FIXED, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(Account::PRODUCTION), true));
        self::assertSame([[Sending::USER, self::RECIPIENT, '7C6BY6U']], FakeActions::$fixed, 'the login, never a uid the form posts');
        self::assertSame([[Sync::LOG_EVENT, 'fixbox:' . self::RECIPIENT, [Sending::USER, 'x2pbqzq', '7c6by6u']]], FakeActions::$logged);
    }

    // the box was there already - the same form again, or set by hand: done, nothing to log
    public function test_fixbox_boxThereAlreadyNotLogged(): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::FIXED, 'old' => '7c6by6u', 'new' => ''];
        self::postFix();

        self::assertSame(Actions::FIXED, $this->actions()->run(self::page(Account::PRODUCTION), true)?->code);
        self::assertSame([], FakeActions::$logged);
    }

    /** @return array<string, array{string, string}> the box the item had, as logged */
    public static function oldBoxes(): array
    {
        return [
            'long text'              => [str_repeat('ž', 100), str_repeat('ž', 16)],
            'colons, escaped by log' => [str_repeat(':', 40), str_repeat(':', 16)],
            'control characters'     => ["a7k\tbr\x00rn\r\n", 'a7kbrrn'],
            'not UTF-8'              => ["a7k\xffbrrn", ''],
        ];
    }

    // the item's text: the row must fit log.params after AA_Log escapes its colons
    #[DataProvider('oldBoxes')]
    public function test_fixbox_logCutsTheOldBox(string $old, string $logged): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::FIXED, 'old' => $old, 'new' => '7c6by6u'];
        self::postFix();

        $this->actions()->run(self::page(Account::PRODUCTION), true);

        self::assertSame($logged, FakeActions::$logged[0][2][1] ?? null);
        self::assertLessThanOrEqual(32, strlen(str_replace(':', '#:', $logged)));
    }

    /** @return array<string, array{string}> what the core refuses with - a code of Actions too */
    public static function fixRefusals(): array
    {
        $codes = [Sync::NOT_OFFERED, Sync::NO_REGISTER, Sync::FORBIDDEN, Sync::UNKNOWN_RECIPIENT, Sync::SETTINGS];

        return array_combine($codes, array_map(static fn(string $code): array => [$code], $codes));
    }

    #[DataProvider('fixRefusals')]
    public function test_fixbox_refusalNotLogged(string $code): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => $code, 'old' => '', 'new' => ''];
        self::postFix();

        self::assertEquals(new ActionResult('fixbox', ActionResult::REFUSED, $code, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(Account::PRODUCTION), true));
        self::assertSame([[], []], [FakeActions::$logged, FakeActions::$failures]);
        self::assertNotSame('', Advice::text($code));
    }

    public function test_fixbox_busy(): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::BUSY, 'old' => '', 'new' => ''];
        self::postFix();

        self::assertEquals(new ActionResult('fixbox', ActionResult::BUSY, Actions::BUSY, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(Account::PRODUCTION), true));
    }

    public function test_fixbox_recipientNoItemIdUnknown(): void
    {
        self::postFix(recipient: self::RECIPIENT . '"');

        self::assertEquals(new ActionResult('fixbox', ActionResult::REFUSED, Actions::UNKNOWN_RECIPIENT, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(Account::PRODUCTION), true));
        self::assertSame([], FakeActions::$fixed);
    }

    // a campaign of no page here: nothing fixed, whatever token the form carries
    public function test_fixbox_campaignOfOtherSliceUnknown(): void
    {
        $this->campaigns[self::OTHER_ITEM] = self::item(Sending::MESSAGE);
        self::postFix(campaign: self::OTHER_ITEM);

        self::assertEquals(new ActionResult('fixbox', ActionResult::REFUSED, Actions::UNKNOWN, self::OTHER_ITEM), $this->actions()->run(self::page(Account::PRODUCTION), true));
        self::assertSame([], FakeActions::$fixed);
    }

    // Setup checked the settings of data boxes of the source slice; gone since, it is a fault, which the State Check counts
    public function test_fixbox_sourceWithoutSettingsInternal(): void
    {
        FakeActions::$rules = new FakeSliceRules();
        self::postFix();

        self::assertEquals(new ActionResult('fixbox', ActionResult::FAILED, Actions::INTERNAL, self::CAMPAIGN_ITEM), $this->actions()->run(self::page(Account::PRODUCTION), true));
        self::assertSame([], FakeActions::$logged);
        self::assertSame('fixbox:' . self::CAMPAIGN_ITEM, FakeActions::$failures[0][0] ?? null);
        self::assertMatchesRegularExpression('/^#' . Sending::SITE . ' RuntimeException .*SliceRules\.php:\d+$/', FakeActions::$failures[0][1]);
    }

    /** @return array<string, array{string}> */
    public static function everyAction(): array
    {
        // a literal list: a data provider runs before Gate can be loaded
        $actions = ['save', 'discard', 'clone', 'test', 'send', 'stop', 'abandon', 'resume', 'verify', 'fixbox'];

        return array_combine($actions, array_map(static fn(string $action): array => [$action], $actions));
    }

    // every action a form may name has its own way: none ends in the fallback of act()
    #[DataProvider('everyAction')]
    public function test_run_everyActionOfTheGateRuns(string $action): void
    {
        self::assertEqualsCanonicalizing(Gate::ACTIONS, array_keys(self::everyAction()), 'the list is that of the gate');
        self::inError();
        FakeActions::$stop   = static fn(string $c, string $u, bool $abandon): string => $abandon ? Actions::ABANDONED : '';
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::NOT_OFFERED, 'old' => '', 'new' => ''];
        self::post($action, self::page(Account::PRODUCTION));

        $this->actions()->run(self::page(Account::PRODUCTION), true);

        // the core of save, discard, test and send is not given: a failure, yet in the fake, past the fallback
        foreach (FakeActions::$failures as [, $params]) {
            self::assertDoesNotMatchRegularExpression('~ LogicException \S*(?<!Fake)Actions\.php:\d+$~', $params);
        }
    }
}
