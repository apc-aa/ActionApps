<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Account;
use AA\Isds\ActionResult;
use AA\Isds\Actions;
use AA\Isds\Gate;
use AA\Isds\SendQueue;
use AA\Isds\SendRun;
use AA\Isds\Setup;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\FakeActions;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';
require_once __DIR__ . '/fixtures/FakeActions.php';

/**
 * The actions of a page of a mass sending: only a POST of this origin with the token of the form the
 * gate gave for the action, the binding of the page and the campaign acts - once a request, with the
 * login of the request. Continue, Stop and Abandon reach the real run (SendRun over the tables in memory).
 */
final class ActionsTest extends TestCase
{
    use SendRunCase {
        setUp as private caseSetUp;
        tearDown as private caseTearDown;
    }

    private const OTHER_ITEM = 'ca000000000000000000000000000c02';

    /** @var array<string, array{slice: string, active: bool, filter: string, state: string}> what the gate reads of an item */
    private array $campaigns = [];

    /** @var array<string, mixed> $_SERVER before the test */
    private array $server = [];

    protected function setUp(): void
    {
        $this->caseSetUp();
        FakeActions::reset();
        FakeActions::$resume = static fn(string $campaign, string $uid): string => TestSendRun::resumeFor($campaign, $uid);
        FakeActions::$stop   = static fn(string $campaign, string $uid, bool $abandon): string => TestSendRun::stop($campaign, $uid, $abandon);
        $this->server        = $_SERVER;
        $this->campaigns     = [self::CAMPAIGN_ITEM => self::item()];
        session_id('actions-test-session');
    }

    protected function tearDown(): void
    {
        $_SERVER  = $this->server;
        $_POST    = [];
        $_GET     = [];
        $_COOKIE  = [];
        $_REQUEST = [];
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

    // a form of an action this page does not run yet is no action either
    public function test_run_actionNotRunYetExpired(): void
    {
        self::inError();
        self::post('verify');

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
}
