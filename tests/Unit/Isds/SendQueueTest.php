<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Http\Response;
use AA\IO\DB\DB_AA;
use AA\Isds\Account;
use AA\Isds\Outcome;
use AA\Isds\Recipients;
use AA\Isds\Registry\Config;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Setup;
use AA\Isds\Snapshot;
use AA\Optimize\Finding;
use AA_Credentials;
use AA_Items;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Fixtures\Certificates;
use Tests\Unit\Isds\Fixtures\FakeGate;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\ScriptedIsds;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendQueue;
use Tests\Unit\Isds\Fixtures\TestSendRun;
use Tests\Unit\Optimize\Fixtures\TestIsdsRunsCheck;

require_once __DIR__ . '/fixtures/SendRunCase.php';
require_once __DIR__ . '/fixtures/FakeSliceRules.php';
require_once __DIR__ . '/fixtures/FakeRecipients.php';
require_once __DIR__ . '/fixtures/FakeGate.php';
require_once __DIR__ . '/fixtures/TestSendQueue.php';
require_once __DIR__ . '/../Optimize/fixtures/IsdsRunChecks.php';
require_once __DIR__ . '/../fixtures/Certificates.php';

/**
 * Queueing a mass sending: the checks, ISDS asked whose the login is outside the lock and the
 * transaction, the snapshot, the run and the campaign written at once or not at all - over TableDb's
 * object_text and toexecute, the campaign item and the message items in memory, ISDS from scripts.
 */
final class SendQueueTest extends TestCase
{
    use SendRunCase {
        setUpBeforeClass as private caseSetUpBeforeClass;
        setUp as private caseSetUp;
        tearDown as private caseTearDown;
    }

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    // the territory: Benešov has no box, Děčín is a city district
    private const ADAMOV  = 'a0000000000000000000000000000001';
    private const BENESOV = 'b0000000000000000000000000000002';
    private const CHEB    = 'c0000000000000000000000000000003';
    private const DECIN   = 'd0000000000000000000000000000004';

    /** an hour and the retries of a run that did not pause (SendRun::retryDelay()): 1 + 60 + 120 s */
    private const HANGS_AFTER = 3600 + 1 + 60 + 120;

    /** how often computed() ran - an entry of the soft cache computed again was cleared */
    private static int $computed = 0;

    private FakeSliceRules $rules;
    private FakeRecipients $territory;
    private TestSendQueue $sending;
    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::caseSetUpBeforeClass();
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode() of the gate's settings
    }

    protected function setUp(): void
    {
        $this->caseSetUp();
        FakeRecipients::forget();
        TableDb::$engines += ['item' => 'InnoDB', 'content' => 'InnoDB'];
        $this->rules     = FakeSliceRules::ready(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE)->withSource(Sending::SITE, Sending::SOURCE);
        $this->territory = new FakeRecipients([
            self::ADAMOV  => Sending::item('Adamov', 'mesto', '0', 'aaa0001'),
            self::BENESOV => Sending::item('Benešov', 'mesto', '1', ''),
            self::CHEB    => Sending::item('Cheb', 'mesto', '0', 'ccc0003'),
            self::DECIN   => Sending::item('Děčín', 'mestocast', '0', ''),
        ]);
        $this->dir = sys_get_temp_dir() . '/isds-queue-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents("$this->dir/pozvanka.pdf", self::PDF);
        $this->sending        = new TestSendQueue($this->rules, $this->territory, $this->dir);
        $this->sending->files = [TestSendQueue::UPLOAD_URL . '/pozvanka.pdf'];
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'] = [
            Schema::CAMPAIGN_STATE    => Schema::CAMPAIGN_STATE_DRAFT,
            Schema::CAMPAIGN_SUBJECT  => 'Pozvánka',
            Schema::CAMPAIGN_BODY     => '<p>Vážení, <b>zveme</b> Vás</p>',
            Schema::CAMPAIGN_TO_HANDS => 'starosta',
            Schema::CAMPAIGN_FILTER   => Sending::filter(),
        ];
        self::owner();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        rmdir($this->dir);
        FakeSliceRules::forget();
        FakeRecipients::forget();
        AA::Contentcache()->clear();
        $this->caseTearDown();
    }

    /** cities and districts offered, cities chosen at first, members in Sending::MEMBER */
    private static function settings(): string
    {
        return (string) json_encode(['campaign_slice' => Sending::CAMPAIGN, 'message_slice' => Sending::MESSAGE, 'source_slice' => Sending::SOURCE, 'types' => ['mesto' => 'Cities', 'mestocast' => 'City districts'], 'default_types' => ['mesto'], 'member_field' => Sending::MEMBER]);
    }

    /** the settings of the page as its gate checked them, datovka_conf.php binding the test account */
    private static function page(int $max = Setup::DEFAULT_RECIPIENTS, string $senderBox = ''): Setup
    {
        return Setup::fromSnapshot(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE, Sending::SOURCE, ['mesto' => 'Cities', 'mestocast' => 'City districts'], ['mesto'], Sending::MEMBER, [], $max, Account::TEST, $senderBox, "Národní síť\nPraha");
    }

    /** ISDS says the login is $box's; $status other than 0000 refuses the question */
    private static function owner(string $box = self::SENDER, string $status = '0000', int $code = 200, string $page = ''): ScriptedIsds
    {
        $xml = str_replace(['<p:dbID>' . self::SENDER . '<', '<p:dbStatusCode>0000<'], ["<p:dbID>$box<", "<p:dbStatusCode>$status<"], self::fixture('owner-info.xml'));
        return TestSendRun::$http = new ScriptedIsds(['GetOwnerInfoFromLogin2' => [new Response($code, ($page === '') ? $xml : self::fixture($page))]]);
    }

    /** ISDS for a login with a certificate, which goes through the HTTP client of AA: the login is $box's */
    private static function ownerTransport(string $box): MockHttpClient
    {
        $xml = str_replace('<p:dbID>' . self::SENDER . '<', "<p:dbID>$box<", self::fixture('owner-info.xml'));
        return new MockHttpClient(static fn(): MockResponse => new MockResponse($xml));
    }

    /** queued as the gate queues it: the page's settings, the editor of the campaign slice, the two recipients the preview shows */
    private function send(?Setup $setup = null, string $campaign = self::CAMPAIGN_ITEM, string $uid = Sending::USER, int $confirmed = 2): SendStart
    {
        return $this->sending->queue($setup ?? self::page(), $campaign, $uid, $confirmed);
    }

    /** @return array<string, array<string, string>> run id => its record, of every run */
    private static function runs(): array
    {
        return TestSendRun::recordsOfAll(['state', 'subject']);
    }

    private static function draft(): void
    {
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_DRAFT;
    }

    private static function campaignField(string $field): string
    {
        return self::items()->field(self::CAMPAIGN_ITEM, $field);
    }

    // ------------------------------------------------------------ queued

    public function test_queue_startsTheRunAndQueuesTheCampaign(): void
    {
        $start = $this->send();

        self::assertSame([SendStart::STATUS_QUEUED, '', ''], [$start->status, $start->code, $start->detail]);
        $id     = $start->runId;
        $stored = self::stored($id);
        self::assertSame(['i' => 0, 'p' => 'prep'], self::position($id));
        self::assertSame(
            [self::CAMPAIGN_ITEM, Sending::USER, Sending::SITE, Account::TEST, 'NSZM', Account::BASIC, self::fingerprint(), self::SENDER, Sending::CAMPAIGN, Sending::MESSAGE, TestSendQueue::SHORT, '0', SendRun::SENDING],
            [$stored['subject'], $stored['user'], $stored['site'], $stored['env'], $stored['account'], $stored['kind'], $stored['acct_id'], $stored['sender_box'], $stored['camp_slice'], $stored['msg_slice'], $stored['camp_short'], $stored['cursor'], $stored['state']],
        );
        self::assertSame(['', ''], [$stored['owner_at'] ?? '', $stored['camp_state'] ?? ''], 'the first step of sending asks ISDS whose the login is again');
        self::assertSame([[self::ADAMOV, 'aaa0001', ''], [self::BENESOV, '', Schema::BOX_MISSING], [self::CHEB, 'ccc0003', '']], array_map(static fn(array $r): array => [$r['src'], $r['box'], $r['reason']], self::rows($id)), 'skipped ones frozen too, in the order of names');
        self::assertCount(1, self::queue(), 'its first step queued');
        self::assertSame(
            [Schema::CAMPAIGN_STATE_QUEUED, '', Account::TEST, self::SENDER, '2', Sending::USER, Sending::USER, (string) self::NOW],
            array_map(self::campaignField(...), [Schema::CAMPAIGN_STATE, Schema::CAMPAIGN_ERROR_TEXT, Schema::CAMPAIGN_ENVIRONMENT, Schema::CAMPAIGN_SENDER_BOX, Schema::CAMPAIGN_RECIPIENTS, Schema::CAMPAIGN_SENT_BY, Schema::CAMPAIGN_SENT_BY_NAME, Schema::CAMPAIGN_STARTED_AT]),
        );
        self::assertSame([[self::CAMPAIGN_ITEM, Sending::USER, $id]], $this->sending->logged);
        self::assertSame(['lock ' . self::CAMPAIGN_ITEM, 'unlock ' . self::CAMPAIGN_ITEM], $this->sending->lockLog, 'after the commit, outside the transaction');
    }

    public function test_queue_lettersFrozen(): void
    {
        $stored = self::stored($this->send()->runId);

        $letter = Snapshot::read($stored['snapshot'], $stored['manifest'], true, self::CAMPAIGN_ITEM)->letter();

        self::assertSame(['Pozvánka', 'starosta'], [$letter->annotation, $letter->toHands]);
        self::assertSame(self::PDF, $letter->files[1]->content);
        self::assertStringContainsString('Národní síť<br>', $letter->files[0]->content, 'the letterhead of the page');
    }

    // a campaign id in capitals is the same campaign
    public function test_queue_campaignIdInCapitals(): void
    {
        $start = $this->send(campaign: strtoupper(self::CAMPAIGN_ITEM));

        self::assertSame(SendStart::STATUS_QUEUED, $start->status);
        self::assertSame(self::CAMPAIGN_ITEM, self::stored($start->runId)['subject']);
    }

    // what memory is in use, a reserve and twice the files: an error of one per byte misses by the file
    public function test_queue_raisesMemoryLimitForTheFiles(): void
    {
        file_put_contents("$this->dir/pozvanka.pdf", self::PDF . random_bytes(4 * 1024 * 1024));
        TestSendRun::$memoryLimit = 1024;
        TestSendRun::$mayRaise    = true;

        $start = $this->send();

        self::assertSame(SendStart::STATUS_QUEUED, $start->status);
        $files = Snapshot::filesSize(self::stored($start->runId)['manifest']);
        self::assertGreaterThan(4 * 1024 * 1024, $files);
        self::assertEqualsWithDelta($this->sending->usageAtCheck + 16 * 1024 * 1024 + 2 * $files, TestSendRun::$raisedTo, 2 * 1024 * 1024, 'memory_get_usage(true) grows by 2 MB chunks');
    }

    public function test_queue_asManyAsThePageSends(): void
    {
        self::assertSame(SendStart::STATUS_QUEUED, $this->send(self::page(2))->status);
    }

    // ------------------------------------------------------------ RF5: one run, whoever clicks twice

    public function test_queue_refusesSecondStart(): void
    {
        $first = $this->send();

        $second = $this->send();

        self::assertSame([SendStart::STATUS_REFUSED, SendStart::NOT_DRAFT], [$second->status, $second->code]);
        self::assertSame([$first->runId], array_keys(self::runs()));
        self::assertCount(1, self::queue());
    }

    public function test_queue_busyWhileLocked(): void
    {
        $this->sending->lockedByOther = true;
        $before                       = TableDb::$tables;

        $start = $this->send();

        self::assertSame([SendStart::STATUS_BUSY, SendStart::BUSY], [$start->status, $start->code]);
        self::assertSame($before, TableDb::$tables, 'nothing written');
        self::assertSame(Schema::CAMPAIGN_STATE_DRAFT, self::campaignField(Schema::CAMPAIGN_STATE));
        self::assertSame(['lock ' . self::CAMPAIGN_ITEM], $this->sending->lockLog, 'no unlock of a lock not got');
    }

    // RF7: aa[] of a filler sets the state back - the run is the proof the campaign was sent
    public function test_queue_refusesAfterStateResetToDraft(): void
    {
        $first = $this->send();
        self::draft();

        $second = $this->send();

        self::assertSame([SendStart::STATUS_REFUSED, SendStart::ALREADY], [$second->status, $second->code]);
        self::assertSame([$first->runId], array_keys(self::runs()));
    }

    // a run started past the check by something that took no lock: looked for again in the transaction
    public function test_queue_runStartedMeanwhile_writesNothing(): void
    {
        $other = '';
        $this->sending->beforeLetter = static function () use (&$other): void {
            $other = self::queued(1);
        };

        $start = $this->send();

        self::assertSame(SendStart::ALREADY, $start->code);
        self::assertSame([$other], array_keys(self::runs()));
        self::assertSame(1, count(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && ($r['value'] === Snapshot::class))), 'the snapshot of the other run only');
        self::assertSame(Schema::CAMPAIGN_STATE_DRAFT, self::campaignField(Schema::CAMPAIGN_STATE));
    }

    // the campaign is checked before ISDS is asked and again under the lock: another tab may queue it meanwhile
    public function test_queue_queuedWhileIsdsAnswered_refused(): void
    {
        $xml               = self::fixture('owner-info.xml');
        TestSendRun::$http = new ScriptedIsds(['GetOwnerInfoFromLogin2' => [static function () use ($xml): Response {
            self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_QUEUED;
            return new Response(200, $xml);
        }]]);
        $before = TableDb::$tables;

        $start = $this->send();

        self::assertSame(SendStart::NOT_DRAFT, $start->code);
        self::assertSame($before, TableDb::$tables, 'nothing written');
    }

    /** @return array<string, array{\Closure(): void}> */
    public static function failedWrites(): array
    {
        return [
            'the snapshot'      => [static function (): void {
                TableDb::$fail = static fn(string $sql): bool => str_contains($sql, "'rcpt'");
            }],
            'the campaign item' => [static function (): void {
                self::items()->onWrite = static fn() => throw new \RuntimeException('Campaign item not stored');
            }],
        ];
    }

    /** an entry of the soft cache, as an expression the request read */
    public static function computed(): int
    {
        return ++self::$computed;
    }

    #[DataProvider('failedWrites')]
    public function test_queue_rollsBackWhenAWriteFails(\Closure $fail): void
    {
        // open already, so that withSlicesOpen() closes nothing and leaves the caches be
        AA_Credentials::singleton()->openForRequest(Sending::CAMPAIGN, Sending::MESSAGE);
        self::$computed = 0;
        AA::Contentcache()->get_result([self::class, 'computed']);
        $itemCache = new \ReflectionProperty(AA_Items::class, '_i');
        $itemCache->setValue(AA_Items::singleton(), [PHP_INT_MAX => null]);
        $fail();

        try {
            $this->send();
            self::fail('A write that failed is no queued sending');
        } catch (\RuntimeException) {
        }

        self::assertSame([[], []], [TableDb::rows('object_text'), self::queue()], 'no snapshot, no run, no step');
        self::assertSame(Schema::CAMPAIGN_STATE_DRAFT, self::campaignField(Schema::CAMPAIGN_STATE));
        self::assertSame([], $this->sending->logged);
        self::assertSame(['lock ' . self::CAMPAIGN_ITEM, 'unlock ' . self::CAMPAIGN_ITEM], $this->sending->lockLog);
        self::assertSame([], $itemCache->getValue(AA_Items::singleton()), 'the items read are read again');
        self::assertSame(2, AA::Contentcache()->get_result([self::class, 'computed']), 'the expressions read are read again');
    }

    // a failure of the database while the recipients are searched is no refusal of the settings
    public function test_queue_searchFails_throws(): void
    {
        $this->sending->resolver = new class extends Recipients {
            protected function search(string $slice, array $conds, ?array $restrict = null): array
            {
                throw new \RuntimeException('MySQL server has gone away');
            }
        };
        $before = TableDb::$tables;

        try {
            $this->send();
            self::fail('The failure goes up');
        } catch (\RuntimeException $e) {
            self::assertSame('MySQL server has gone away', $e->getMessage());
        }

        self::assertSame($before, TableDb::$tables, 'nothing written');
        self::assertSame(Schema::CAMPAIGN_STATE_DRAFT, self::campaignField(Schema::CAMPAIGN_STATE));
        self::assertSame(['lock ' . self::CAMPAIGN_ITEM, 'unlock ' . self::CAMPAIGN_ITEM], $this->sending->lockLog);
    }

    public function test_queue_campaignGoneBeforeItsWrite_writesNothing(): void
    {
        $this->sending->beforeLetter = static function (): void {
            unset(self::items()->stored[self::CAMPAIGN_ITEM]);
        };

        $start = $this->send();

        self::assertSame(SendStart::UNKNOWN, $start->code);
        self::assertSame([[], []], [TableDb::rows('object_text'), self::queue()]);
    }

    // ------------------------------------------------------------ RF7: the run sends what was frozen

    public function test_queue_snapshotIgnoresLaterChanges(): void
    {
        $id = $this->send()->runId;
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'] = [Schema::CAMPAIGN_SUBJECT => 'Jiná věc', Schema::CAMPAIGN_FILTER => Sending::filter(['types' => ['mestocast']])] + self::items()->stored[self::CAMPAIGN_ITEM]['fields'];
        $this->sending->files = [];
        file_put_contents("$this->dir/pozvanka.pdf", '%PDF-1.4 changed');

        self::assertSame('step 1', self::runQueued());

        $stored = self::stored($id);
        $letter = Snapshot::read($stored['snapshot'], $stored['manifest'], true, self::CAMPAIGN_ITEM)->letter();
        self::assertSame(['Pozvánka', self::PDF], [$letter->annotation, $letter->files[1]->content]);
        $made = array_map(static fn(array $r): array => [self::items()->field($r['msg'], Schema::MESSAGE_NAME), self::items()->field($r['msg'], Schema::MESSAGE_BOX)], self::rows($id));
        self::assertSame([['Adamov', 'aaa0001'], ['Benešov', ''], ['Cheb', 'ccc0003']], $made, 'the message items of the frozen recipients');
    }

    // ------------------------------------------------------------ ISDS, secrets, the preview

    public function test_queue_callsIsdsOutsideTransactionAndLock(): void
    {
        $asked = [];
        $xml   = self::fixture('owner-info.xml');
        TestSendRun::$http = new ScriptedIsds(['GetOwnerInfoFromLogin2' => [function () use (&$asked, $xml): Response {
            $asked[] = [DB_AA::inTransaction(), $this->sending->lockLog];
            return new Response(200, $xml);
        }]]);

        self::assertSame(SendStart::STATUS_QUEUED, $this->send()->status);

        self::assertSame([[false, []]], $asked);
    }

    public function test_queue_snapshotHoldsNoSecret(): void
    {
        $start = $this->send();

        $written = json_encode([TableDb::$tables, $start], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([Sending::PASSWORD, Sending::LOGIN] as $secret) {
            self::assertStringNotContainsString($secret, $written);
            self::assertStringNotContainsString(base64_encode($secret), $written);
            self::assertStringNotContainsString(base64_encode(Sending::LOGIN . ':' . Sending::PASSWORD), $written);
        }
    }

    // the count the page shows is the count the user confirms, and the count frozen
    public function test_queue_countsAsPreview(): void
    {
        $gate            = new FakeGate(Sending::SITE, $this->rules, Sending::bound(), FakePerms::login(Sending::USER));
        $gate->campaigns = [self::CAMPAIGN_ITEM => ['slice' => Sending::CAMPAIGN, 'active' => true, 'filter' => Sending::filter()]];
        $gate->resolver  = $this->territory;
        $shown           = '';
        $gate->page(self::settings(), '', '', static function () use ($gate, &$shown): string {
            $shown = $gate->recipients(self::CAMPAIGN_ITEM, 'count');
            return '';
        });
        $setup = Setup::checked(self::settings(), Sending::SITE, $this->rules, Sending::bound());
        self::assertInstanceOf(Setup::class, $setup);

        $start = $this->send($setup, confirmed: (int) $shown);

        self::assertSame(['2', SendStart::STATUS_QUEUED], [$shown, $start->status]);
        self::assertSame($shown, self::campaignField(Schema::CAMPAIGN_RECIPIENTS));
        self::assertCount((int) $shown, array_filter(self::rows($start->runId), static fn(array $r): bool => $r['reason'] === ''));
    }

    // a run that never gets a step - a queue that does not run - shows in State Check after an hour
    public function test_queue_runWithoutStep_stateCheckWarnsOfIt(): void
    {
        $id = $this->send()->runId;

        TestSendRun::$now = self::NOW + self::HANGS_AFTER;
        self::assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, (new TestIsdsRunsCheck())->findings()));
        TestSendRun::$now = self::NOW + self::HANGS_AFTER + 1;
        $findings         = (new TestIsdsRunsCheck())->findings();

        self::assertSame([[Finding::WARNING, "stuck:$id"]], array_map(static fn(Finding $f): array => [$f->severity, $f->key], $findings));
        self::assertSame('prep', $findings[0]->evidence[0]['Phase']);
    }

    // ------------------------------------------------------------ refused

    /** @return array<string, array{\Closure(TestSendQueue): array<string, mixed>, string, string}> what to change, the code, a part of the detail */
    public static function refusals(): array
    {
        return [
            'another user'             => [static function (): array {
                // the rights come first: settings that cannot work are no business of a user without them
                TestSendRun::$conf = Sending::fileConf(true);
                return ['uid' => 'stranger'];
            }, SendStart::FORBIDDEN, ''],
            'no long id'               => [static fn(): array => ['campaign' => 'ca00000000000000000000000000c01'], SendStart::UNKNOWN, ''],
            'no such item'             => [static fn(): array => ['campaign' => 'ca000000000000000000000000000c02'], SendStart::UNKNOWN, ''],
            'item of another slice'    => [static function (TestSendQueue $q): array {
                $q->slice = Sending::OTHER;
                return [];
            }, SendStart::UNKNOWN, ''],
            'item in the trash'        => [static function (TestSendQueue $q): array {
                $q->active = false;
                return [];
            }, SendStart::UNKNOWN, ''],
            'queued already'           => [static function (): array {
                self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_QUEUED;
                return [];
            }, SendStart::NOT_DRAFT, ''],
            'a run of it'              => [static function (): array {
                self::queued(1);
                return [];
            }, SendStart::ALREADY, ''],
            'no filter'                => [static fn(): array => self::filterIs(' '), SendStart::NO_FILTER, ''],
            'a filter of another page' => [static fn(): array => self::filterIs(Sending::filter(['types' => ['hrad']])), SendStart::BAD_FILTER, 'hrad'],
            'count changed'            => [static fn(): array => ['confirmed' => 3], SendStart::COUNT_CHANGED, ''],
            'more than the page sends' => [static fn(): array => ['setup' => self::page(1)], SendStart::TOO_MANY, ''],
            'nobody with a box'        => [static fn(): array => self::filterIs(Sending::filter(['types' => ['mestocast']])) + ['confirmed' => 0], SendStart::NOTHING, ''],
            'no account bound'         => [static function (): array {
                TestSendRun::$conf = Sending::conf([]);
                return [];
            }, SendStart::ACCOUNT, 'To bind a test account'],
            'bound to another site'    => [static function (): array {
                TestSendRun::$conf = Sending::bound(Sending::SITE2);
                return [];
            }, SendStart::ACCOUNT, ''],
            'settings unreadable'      => [static function (): array {
                TestSendRun::$conf = Sending::fileConf(false);
                return [];
            }, SendStart::SETTINGS, ''],
            'settings broken'          => [static function (): array {
                TestSendRun::$conf = Sending::fileConf(true);
                return [];
            }, SendStart::SETTINGS, ''],
            'a slice mails its items'  => [static function (TestSendQueue $q): array {
                $q->rules->outlets[Sending::MESSAGE] = ['notify'];
                return [];
            }, SendStart::SETTINGS, 'Email Notification'],
            'the site lost a slice'    => [static function (TestSendQueue $q): array {
                $q->rules->related[Sending::SITE] = [Sending::CAMPAIGN, Sending::SOURCE];
                return [];
            }, SendStart::SETTINGS, ''],
            'the source lost its type' => [static function (TestSendQueue $q): array {
                $q->rules->configs[Sending::SOURCE] = Config::fromValues(Sending::SOURCE, ['name_field' => Sending::NAME, 'ds_field' => Sending::DS]);
                return [];
            }, SendStart::SETTINGS, 'Type field'],
            'no letter'                => [static function (): array {
                self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_BODY] = '';
                return [];
            }, SendStart::CONTENT, ''],
            'a file of another server' => [static function (TestSendQueue $q): array {
                $q->files = ['https://elsewhere.example.org/<b>x</b>.pdf'];
                return [];
            }, SendStart::CONTENT, 'https://elsewhere.example.org/<b>x</b>.pdf'],
            'no memory for the files'  => [static function (): array {
                TestSendRun::$memoryLimit = 1024;
                return [];
            }, SendStart::MEMORY, 'mb:'],
            'content not InnoDB'       => [static function (): array {
                TableDb::$engines['content'] = 'MyISAM';
                return [];
            }, SendStart::NO_TRANSACTION, 'content'],
            'no transaction'           => [static function (): array {
                TableDb::$transactions = false;
                return [];
            }, SendStart::NO_TRANSACTION, ''],
            'login refused'            => [static function (): array {
                self::owner(code: 401, page: 'unauthorized.html');
                return [];
            }, Outcome::LOGIN, ''],
            'address blocked'          => [static function (): array {
                self::owner(code: 401, page: 'address-blocked.html');
                return [];
            }, Outcome::BLOCKED, ''],
            'ISDS not answering'       => [static function (): array {
                TestSendRun::$http = new ScriptedIsds(['GetOwnerInfoFromLogin2' => [new Response(503, 'Service Unavailable')]]);
                return [];
            }, Outcome::UNAVAILABLE, ''],
            'ISDS refuses to tell'     => [static function (): array {
                self::owner(status: '1214');
                return [];
            }, SendRun::REFUSED, 'status:1214'],
        ];
    }

    /** @return array<string, mixed> */
    private static function filterIs(string $filter): array
    {
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_FILTER] = $filter;
        return [];
    }

    /** @param \Closure(TestSendQueue): array<string, mixed> $change what to call with instead, when not the defaults */
    #[DataProvider('refusals')]
    public function test_queue_refused(\Closure $change, string $code, string $detail): void
    {
        $call     = $change($this->sending) + ['setup' => null, 'campaign' => self::CAMPAIGN_ITEM, 'uid' => Sending::USER, 'confirmed' => 2];
        if (in_array($code, [SendStart::FORBIDDEN, SendStart::UNKNOWN, SendStart::NOT_DRAFT, SendStart::ALREADY, SendStart::SETTINGS, SendStart::ACCOUNT], true)) {
            // refused before ISDS: each login there counts
            TestSendRun::$http = null;
        }
        $state    = self::campaignField(Schema::CAMPAIGN_STATE);
        $before   = TableDb::$tables;

        $start = $this->send($call['setup'], $call['campaign'], $call['uid'], $call['confirmed']);

        self::assertSame([SendStart::STATUS_REFUSED, $code, ''], [$start->status, $start->code, $start->runId]);
        self::assertStringContainsString($detail, $start->detail);
        if ($code === SendStart::FORBIDDEN) {
            self::assertSame('', $start->detail);
        }
        self::assertSame($before, TableDb::$tables, 'nothing written');
        self::assertSame([$state, []], [self::campaignField(Schema::CAMPAIGN_STATE), $this->sending->logged]);
        $locks = $this->sending->lockLog;
        self::assertSame(array_map(static fn(string $l): string => "un$l", array_values(array_filter($locks, static fn(string $l): bool => str_starts_with($l, 'lock ')))), array_values(array_filter($locks, static fn(string $l): bool => str_starts_with($l, 'unlock '))), 'every lock released, outside a transaction');
    }

    // a hosted filing service sends from the box the page names: a login of another box would stop the run at its first message
    public function test_queue_hostedLoginOfAnotherBox_refused(): void
    {
        $dir                 = Certificates::directory();
        [$certificate, $key] = Certificates::make();
        try {
            TestSendRun::$conf = Sending::conf(['NSZM' => Sending::account(over: ['auth' => 'hosted', 'login' => null, 'password' => null, 'box' => self::SENDER, 'cert' => Certificates::file($dir, 'both.pem', $certificate . $key)])]);
            $page              = self::page(senderBox: self::SENDER);

            $this->sending->transport = self::ownerTransport('ab12cde');
            self::assertSame(SendStart::ACCOUNT, $this->send($page)->code);
            $this->sending->transport = self::ownerTransport(self::SENDER);
            $start                    = $this->send($page);
            self::assertSame(SendStart::STATUS_QUEUED, $start->status);
            self::assertSame([Account::HOSTED, self::SENDER], [self::stored($start->runId)['kind'], self::stored($start->runId)['sender_box']]);
        } finally {
            Certificates::remove($dir);
        }
    }

    public function test_queue_insideATransaction_throws(): void
    {
        $this->expectException(\LogicException::class);

        DB_AA::transaction(fn(): SendStart => $this->send());
    }

    public function test_refused_takesACode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SendStart::refused('');
    }
}
