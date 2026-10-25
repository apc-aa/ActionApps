<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Http\Response;
use AA\Http\TransportException as HttpTransportException;
use AA\IO\DB\DB_AA;
use AA\Isds\Account;
use AA\Isds\Body\HtmlBody;
use AA\Isds\Body\PdfBody;
use AA\Isds\Client;
use AA\Isds\Message;
use AA\Isds\Outcome;
use AA\Isds\Recipients;
use AA\Isds\Registry\Config;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Setup;
use AA\Isds\Snapshot;
use AA\Isds\TestResult;
use AA\Optimize\Finding;
use AA\Upload\UploadDir;
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
use Tests\Unit\Isds\Fixtures\PdfText;
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
require_once __DIR__ . '/fixtures/PdfText.php';
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

    /** the test boxes of the page */
    private const TEST_BOX  = 'dj7j6mk';
    private const TEST_BOX2 = 'ab12cde';

    /** how often computed() ran - an entry of the soft cache computed again was cleared */
    private static int $computed = 0;

    /** the certificates of a production account, when a test made them */
    private string $certificates = '';

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
        if ($this->certificates !== '') {
            Certificates::remove($this->certificates);
        }
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
        self::assertSame(['lock ' . SendRun::lockName(self::CAMPAIGN_ITEM), 'unlock ' . SendRun::lockName(self::CAMPAIGN_ITEM)], $this->sending->lockLog, 'after the commit, outside the transaction');
    }

    public function test_queue_lettersFrozen(): void
    {
        $stored = self::stored($this->send()->runId);

        $letter = Snapshot::read($stored['snapshot'], $stored['manifest'], true, self::CAMPAIGN_ITEM)->letter();

        self::assertSame(['Pozvánka', 'starosta'], [$letter->annotation, $letter->toHands]);
        self::assertSame(self::PDF, $letter->files[1]->content);
        self::assertStringStartsWith("Národní síť\nPraha\n", PdfText::of($letter->files[0]->content), 'the letterhead of the page');
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
        self::assertSame(['lock ' . SendRun::lockName(self::CAMPAIGN_ITEM)], $this->sending->lockLog, 'no unlock of a lock not got');
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
        self::assertSame(['lock ' . SendRun::lockName(self::CAMPAIGN_ITEM), 'unlock ' . SendRun::lockName(self::CAMPAIGN_ITEM)], $this->sending->lockLog);
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
        self::assertSame(['lock ' . SendRun::lockName(self::CAMPAIGN_ITEM), 'unlock ' . SendRun::lockName(self::CAMPAIGN_ITEM)], $this->sending->lockLog);
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

    // ------------------------------------------------------------ a test message of the draft

    /** the page with its test boxes, its account in $environment */
    private static function testPage(array $boxes = [self::TEST_BOX], string $environment = Account::TEST, string $senderBox = ''): Setup
    {
        return Setup::fromSnapshot(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE, Sending::SOURCE, ['mesto' => 'Cities'], ['mesto'], Sending::MEMBER, $boxes, Setup::DEFAULT_RECIPIENTS, $environment, $senderBox, "Národní síť\nPraha");
    }

    /** PDZSendInfo as ISDS answers it: $paid the result, $status the status of the answer */
    private static function paidAnswer(string $paid = 'false', string $status = '0000'): string
    {
        return str_replace(['>true<', '>0000<'], [">$paid<", ">$status<"], self::fixture('pdz-send-info.xml'));
    }

    /**
     * ISDS of the test account: whether a message would be paid, each message accepted - numbered 2001,
     * 2002, ... - unless $create says else
     * @param list<Response|\Throwable|\Closure(): Response> $create
     */
    private static function testIsds(string $paid = 'false', array $create = []): ScriptedIsds
    {
        $next = 2001;
        $made = static function () use (&$next): Response {
            return self::created((string) $next++);
        };
        return TestSendRun::$http = new ScriptedIsds(['PDZSendInfo' => [new Response(200, self::paidAnswer($paid))], 'CreateMessage' => $create ?: [$made]]);
    }

    /** as the gate sends one: the page's settings, the editor of the campaign slice */
    private function test(?Setup $setup = null, string $box = self::TEST_BOX, string $uid = Sending::USER, string $campaign = self::CAMPAIGN_ITEM): TestResult
    {
        return $this->sending->test($setup ?? self::testPage(), $campaign, $uid, $box);
    }

    /** @return array<string, array<string, string>> item id => the fields of each test message stored */
    private static function testItems(): array
    {
        $tests = array_filter(self::items()->stored, static fn(array $item): bool => ($item['fields'][Schema::MESSAGE_TEST] ?? '') === '1');
        return array_map(static fn(array $item): array => $item['fields'], $tests);
    }

    /** @return list<array<string, mixed>> the timeline of the message item $msg */
    private static function timeline(string $msg): array
    {
        return json_decode(self::items()->field($msg, Schema::MESSAGE_EVENTS), true);
    }

    /** the subject the page keeps, as the draft changes */
    private static function subjectIs(string $subject): void
    {
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_SUBJECT] = $subject;
    }

    /**
     * The production account of a hosted filing service sending from SENDER, with $testBoxes; ISDS answers
     * PDZSendInfo with $paid and accepts each message - $asked lists the operations
     * @param list<string> $testBoxes
     * @param list<string> $asked
     * @param string       $page        PDZSendInfo answers with this fixture and $code instead
     * @param string       $certificate the PEM of the account; '' = a sound one
     * @param mixed        $paidTests   paid_tests of the account; null leaves it out
     */
    private function production(array $testBoxes, string $paid, array &$asked, string $status = '0000', string $page = '', int $code = 200, string $certificate = '', mixed $paidTests = null): void
    {
        $this->certificates  = Certificates::directory();
        [$sound, $key]       = Certificates::make();
        $over                = ['environment' => 'production', 'auth' => 'hosted', 'login' => null, 'password' => null, 'box' => self::SENDER, 'cert' => Certificates::file($this->certificates, 'both.pem', ($certificate === '') ? $sound . $key : $certificate), 'test_boxes' => $testBoxes] + (is_null($paidTests) ? [] : ['paid_tests' => $paidTests]);
        TestSendRun::$conf   = Sending::conf(['NSZM' => Sending::account(over: $over)]);
        $paidXml             = ($page === '') ? self::paidAnswer($paid, $status) : self::fixture($page);
        $created             = self::fixture('create-message-ok.xml');
        $this->sending->transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$asked, $paidXml, $code, $created): MockResponse {
            $op      = (preg_match('~<\w+:Body>\s*<\w+:(\w+)~', (string) ($options['body'] ?? ''), $m) === 1) ? $m[1] : '?';
            $asked[] = $op;
            return match ($op) {
                'PDZSendInfo'   => new MockResponse($paidXml, ['http_code' => $code]),
                'CreateMessage' => new MockResponse($created),
                default         => throw new \LogicException("ISDS was asked $op"),
            };
        });
    }

    public function test_test_onlyToTestBox(): void
    {
        $isds = self::testIsds();

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::NOT_TEST_BOX], [$this->test(box: self::TEST_BOX2)->status, $this->test(box: self::TEST_BOX2)->code]);
        self::assertSame([], $isds->ops(), 'nothing asked of ISDS for a box of no list');
        $result = $this->test(box: ' DJ7J6MK ');

        self::assertSame([TestResult::STATUS_SENT, '', self::TEST_BOX, '4711-T2', '2001', false], [$result->status, $result->code, $result->box, $result->ref, $result->dmId, $result->paid]);
        self::assertSame(['PDZSendInfo', 'CreateMessage'], $isds->ops());
        self::assertSame([self::TEST_BOX], $isds->sent());
        self::assertSame(['4711-T2'], $isds->sent('dmSenderRefNumber'));
        $item = self::testItems()[$result->msg];
        self::assertSame(
            [self::TEST_BOX, self::TEST_BOX, self::CAMPAIGN_ITEM, '', '1', Account::TEST, '', Schema::MESSAGE_STATE_SENT, '2001', '4711-T2', (string) self::NOW],
            [$item[Schema::MESSAGE_BOX], $item[Schema::MESSAGE_NAME], $item[Schema::MESSAGE_CAMPAIGN], $item[Schema::MESSAGE_RECIPIENT], $item[Schema::MESSAGE_TEST], $item[Schema::MESSAGE_ENVIRONMENT], $item[Schema::MESSAGE_SENDER_BOX], $item[Schema::MESSAGE_STATE], $item[Schema::MESSAGE_DM_ID], $item[Schema::MESSAGE_SENDER_REF], $item[Schema::MESSAGE_SENT_AT]],
        );
        self::assertSame([Schema::EVENT_QUEUED, Schema::EVENT_SENDING, Schema::EVENT_SUBMITTED], array_column(self::timeline($result->msg), 'kind'));
        self::assertSame([[self::CAMPAIGN_ITEM, $result->msg]], array_map(static fn(array $l): array => array_slice($l, 0, 2), $this->sending->testLogged));
        self::assertSame([Client::TIMEOUT + Client::CREATE_TIMEOUT + 60], $this->sending->keptRunning);
        self::assertSame(['lock isds-test:' . Sending::MESSAGE, 'unlock isds-test:' . Sending::MESSAGE], $this->sending->lockLog, 'outside a transaction');
    }

    // what the request names is a box or nothing; it is compared as ISDS knows boxes
    public function test_test_garbageBoxIsNotTestBox(): void
    {
        TestSendRun::$http = null;
        $two               = self::testPage([self::TEST_BOX, self::TEST_BOX2]);

        foreach (['<b>dj7j6mk</b>', 'dj7j6m', 'dj7j6mk1', 'dj7j 6mk', ''] as $box) {
            self::assertSame(TestResult::NOT_TEST_BOX, $this->test($two, $box)->code, $box);
        }
        self::assertSame([], self::testItems());
    }

    public function test_test_oneTestBoxNeedsNoChoice(): void
    {
        $isds = self::testIsds();

        self::assertSame(TestResult::STATUS_SENT, $this->test(box: '')->status);
        self::assertSame([self::TEST_BOX], $isds->sent());
    }

    public function test_test_refusesNotDraft(): void
    {
        TestSendRun::$http = null;
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_SENDING;

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::NOT_DRAFT], [$this->test()->status, $this->test()->code]);
        self::assertSame([], self::testItems());
    }

    public function test_test_refusesForeignUser(): void
    {
        TestSendRun::$http = null;

        $result = $this->test(uid: 'stranger');

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::FORBIDDEN, ''], [$result->status, $result->code, $result->detail]);
        self::assertSame([[], []], [self::testItems(), $this->sending->lockLog]);
    }

    /** @return array<string, array{\Closure(TestSendQueue): array<string, mixed>, string}> what to change, the code */
    public static function refusedTests(): array
    {
        return [
            'no long id'          => [static fn(): array => ['campaign' => 'ca00000000000000000000000000c01'], TestResult::UNKNOWN],
            'item in the trash'   => [static function (TestSendQueue $q): array {
                $q->active = false;
                return [];
            }, TestResult::UNKNOWN],
            'a run of it'         => [static function (): array {
                self::queued(1);
                return [];
            }, TestResult::ALREADY],
            'no test box'         => [static fn(): array => ['setup' => self::testPage([])], TestResult::NO_TEST_BOX],
            'the own box'         => [static fn(): array => ['setup' => self::testPage([self::TEST_BOX], senderBox: self::TEST_BOX)], TestResult::OWN_BOX],
            'no account bound'    => [static function (): array {
                TestSendRun::$conf = Sending::conf([]);
                return [];
            }, SendStart::ACCOUNT],
            'a slice mails'       => [static function (TestSendQueue $q): array {
                $q->rules->outlets[Sending::MESSAGE] = ['notify'];
                return [];
            }, SendStart::SETTINGS],
            'ISDS maintained'     => [static function (): array {
                TestSendRun::$now = (new \DateTimeImmutable('2030-03-22 00:30', new \DateTimeZone('Europe/Prague')))->getTimestamp();
                return [];
            }, TestResult::MAINTENANCE],
            'no letter'           => [static function (): array {
                self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_BODY] = '';
                return [];
            }, SendStart::CONTENT],
            'subject too long'    => [static function (): array {
                self::subjectIs(str_repeat('á', 250));
                return [];
            }, SendStart::CONTENT],
            'no memory to send'   => [static function (): array {
                TestSendRun::$memoryLimit = 1024;
                return [];
            }, SendStart::MEMORY],
        ];
    }

    // the source slice is no business of a test: it has no recipients
    #[DataProvider('refusedTests')]
    public function test_test_refusedBeforeIsds(\Closure $change, string $code): void
    {
        TestSendRun::$http = null;
        $call              = $change($this->sending) + ['setup' => null, 'campaign' => self::CAMPAIGN_ITEM];
        $this->rules->configs[Sending::SOURCE] = Config::fromValues(Sending::SOURCE, ['name_field' => Sending::NAME, 'ds_field' => Sending::DS]);

        $result = $this->test($call['setup'], campaign: $call['campaign']);

        self::assertSame([TestResult::STATUS_REFUSED, $code, ''], [$result->status, $result->code, $result->msg]);
        self::assertSame([], self::testItems());
        self::assertSame([], $this->sending->testLogged);
    }

    public function test_test_refusesPaidInProduction(): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], 'true', $asked);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::PAID], [$result->status, $result->code]);
        self::assertSame(['PDZSendInfo'], $asked);
        self::assertSame([], self::testItems());
    }

    /** @return array<string, array{mixed}> paid_tests that is not true */
    public static function notTrue(): array
    {
        return ['false' => [false], 'a text' => ['yes'], 'a number' => [1]];
    }

    // only true of paid_tests lets a paid test go
    #[DataProvider('notTrue')]
    public function test_test_paidRefusedUnlessPaidTestsIsTrue(mixed $flag): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], 'true', $asked, paidTests: $flag);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::PAID, ['PDZSendInfo']], [$result->status, $result->code, $asked]);
        self::assertSame([], self::testItems());
    }

    // the administrator allowed paid tests: it goes, and the result tells it cost money
    public function test_test_paidGoesWithPaidTests(): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], 'true', $asked, paidTests: true);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_SENT, '1835603', true, TestResult::CHARGED], [$result->status, $result->dmId, $result->paid, $result->charge()]);
        self::assertSame(['PDZSendInfo', 'CreateMessage'], $asked);
        self::assertSame([self::TEST_BOX], array_column(self::testItems(), Schema::MESSAGE_BOX));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3?: int}> the result PDZSendInfo answers with, its status, the status the refusal names, HTTP */
    public static function unknownPaid(): array
    {
        return [
            'no such box'   => ['false', '5001', '5001'],
            'refused'       => ['false', '1214', '1214'],
            'no answer'     => ['Ano', '0000', ''],
            'ISDS down'     => ['false', '0000', '', 503],
        ];
    }

    // a box ISDS does not know is no "free": the user checks the box, nothing is sent
    #[DataProvider('unknownPaid')]
    public function test_test_refusesWhenPaidUnknownInProduction(string $paid, string $status, string $code, int $http = 200): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], $paid, $asked, $status, code: $http);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::PAID_UNKNOWN, $code], [$result->status, $result->code, $result->isdsCode]);
        self::assertSame(['PDZSendInfo'], $asked);
        self::assertSame([], self::testItems());
    }

    // with paid tests allowed a price ISDS did not answer does not stop it - it may cost money, and the result
    // says so; a status ISDS refused with (no such box) stops it as before
    #[DataProvider('unknownPaid')]
    public function test_test_paidUnknownGoesWithPaidTests(string $paid, string $status, string $code, int $http = 200): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], $paid, $asked, $status, code: $http, paidTests: true);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        if ($code === '') {
            self::assertSame([TestResult::STATUS_SENT, null, TestResult::MAY_BE_CHARGED], [$result->status, $result->paid, $result->charge()]);
            self::assertSame(['PDZSendInfo', 'CreateMessage'], $asked);
        } else {
            self::assertSame([TestResult::STATUS_REFUSED, TestResult::PAID_UNKNOWN, $code, ''], [$result->status, $result->code, $result->isdsCode, $result->charge()]);
            self::assertSame(['PDZSendInfo'], $asked);
            self::assertSame([], self::testItems());
        }
    }

    // paid tests allowed, a free one costs nothing
    public function test_test_freeWithPaidTestsCostsNothing(): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], 'false', $asked, paidTests: true);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_SENT, false, false, ''], [$result->status, $result->paid, $result->charged, $result->charge()]);
    }

    /** @return array<string, array{string, string, string}> the page PDZSendInfo answers with, its HTTP status - or a certificate that cannot work -, the run's code */
    public static function loginFailures(): array
    {
        return [
            'login refused'     => ['unauthorized.html', '401', Outcome::LOGIN],
            'address blocked'   => ['address-blocked.html', '401', Outcome::BLOCKED],
            'no certificate'    => ['', 'not a certificate', Outcome::CONFIG],
        ];
    }

    // the login cannot work: CreateMessage would fail alike - nothing sent, nothing paid, no item
    #[DataProvider('loginFailures')]
    public function test_test_loginFailureAskingThePriceIsFailed(string $page, string $how, string $code): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], 'false', $asked, page: $page, code: is_numeric($how) ? (int) $how : 200, certificate: is_numeric($how) ? '' : $how);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_FAILED, $code, ''], [$result->status, $result->code, $result->msg]);
        self::assertSame(($code === Outcome::CONFIG) ? [] : ['PDZSendInfo'], $asked);
        self::assertSame([], self::testItems());
    }

    // in the test ISDS too: no CreateMessage after a refused login
    public function test_test_loginRefusedInTestIsdsSendsNothing(): void
    {
        $isds = TestSendRun::$http = new ScriptedIsds(['PDZSendInfo' => [new Response(401, self::fixture('unauthorized.html'))]]);

        $result = $this->test();

        self::assertSame([TestResult::STATUS_FAILED, Outcome::LOGIN], [$result->status, $result->code]);
        self::assertSame(['PDZSendInfo'], $isds->ops());
        self::assertSame([], self::testItems());
    }

    public function test_test_freeInProductionGoes(): void
    {
        $asked = [];
        $this->production([self::TEST_BOX], 'false', $asked);

        $result = $this->test(self::testPage(environment: Account::PRODUCTION, senderBox: self::SENDER));

        self::assertSame([TestResult::STATUS_SENT, '1835603', false, ''], [$result->status, $result->dmId, $result->paid, $result->charge()]);
        self::assertSame(['PDZSendInfo', 'CreateMessage'], $asked);
        self::assertSame([Account::PRODUCTION, self::SENDER], [self::items()->field($result->msg, Schema::MESSAGE_ENVIRONMENT), self::items()->field($result->msg, Schema::MESSAGE_SENDER_BOX)]);
    }

    // in the test ISDS a paid one only notes it: nothing is paid there
    public function test_test_paidOnlyNotedInTestIsds(): void
    {
        self::testIsds('true');

        $result = $this->test();

        self::assertSame([TestResult::STATUS_SENT, true, ''], [$result->status, $result->paid, $result->charge()]);
    }

    // paid_tests means nothing in the test ISDS
    public function test_test_paidTestsOfTestAccountChangesNothing(): void
    {
        TestSendRun::$conf = Sending::conf(['NSZM' => Sending::account(over: ['paid_tests' => true])]);
        $isds              = self::testIsds('true');

        $result = $this->test();

        self::assertSame([TestResult::STATUS_SENT, true, false, ''], [$result->status, $result->paid, $result->charged, $result->charge()]);
        self::assertSame(['PDZSendInfo', 'CreateMessage'], $isds->ops());
    }

    // the page's list again, as datovka_conf.php has it: a page settled before the file changed, a template's box
    public function test_test_productionBoxesFromConfOnly(): void
    {
        $asked = [];
        $this->production([self::TEST_BOX2], 'false', $asked);

        $result = $this->test(self::testPage([self::TEST_BOX], Account::PRODUCTION, self::SENDER));

        self::assertSame(TestResult::NOT_TEST_BOX, $result->code);
        self::assertSame([], $asked);
    }

    // the environment of the list is the account's: a template cannot add a box to production by saying test
    public function test_test_testListOnlyByAccountEnvironment(): void
    {
        $conf     = static fn(string $environment): \AA\Isds\DatovkaConf => Sending::conf(['NSZM' => Sending::account(over: ['environment' => $environment, 'test_boxes' => [self::TEST_BOX]] + (($environment === Account::PRODUCTION) ? ['auth' => 'cert', 'login' => null, 'password' => null, 'cert' => '/etc/aa/nszm.pem'] : []))]);
        $settings = static fn(array $over): string => (string) json_encode($over + json_decode(self::settings(), true));

        $production = Setup::checked($settings(['test_box' => [self::TEST_BOX2]]), Sending::SITE, $this->rules, $conf(Account::PRODUCTION));
        $narrowed   = Setup::checked($settings(['test_box' => self::TEST_BOX]), Sending::SITE, $this->rules, $conf(Account::PRODUCTION));
        $all        = Setup::checked($settings([]), Sending::SITE, $this->rules, $conf(Account::PRODUCTION));
        $test       = Setup::checked($settings(['test_box' => [self::TEST_BOX2]]), Sending::SITE, $this->rules, $conf(Account::TEST));
        $saysTest   = Setup::checked($settings(['test_box' => [self::TEST_BOX2], 'environment' => Account::TEST]), Sending::SITE, $this->rules, $conf(Account::PRODUCTION));

        self::assertSame([[], [self::TEST_BOX], [self::TEST_BOX], [self::TEST_BOX2]], [$production->testBoxes ?? null, $narrowed->testBoxes ?? null, $all->testBoxes ?? null, $test->testBoxes ?? null]);
        self::assertInstanceOf(Setup::class, $production);
        TestSendRun::$http = null;
        self::assertSame(TestResult::NO_TEST_BOX, $this->test($production)->code, 'the page sends; its box of no list tests nothing');
        self::assertInstanceOf(\AA\Isds\SetupException::class, $saysTest, 'no test account is bound');
    }

    public function test_test_busyWhileLocked(): void
    {
        self::testIsds();
        $this->sending->lockedByOther = true;

        $result = $this->test();

        self::assertSame([TestResult::STATUS_BUSY, TestResult::BUSY], [$result->status, $result->code]);
        self::assertSame([[], [], ['lock isds-test:' . Sending::MESSAGE]], [self::testItems(), TestSendRun::$http?->ops() ?? null, $this->sending->lockLog]);
    }

    // a process that died while ISDS had the message leaves it sending: busy while its request could still run, then uncertain
    public function test_test_deadSendingBecomesUncertain(): void
    {
        $isds = self::testIsds();
        self::items()->onWrite = static function (): void {
            $last = self::items()->writes[array_key_last(self::items()->writes)];
            if (($last['fields'][Schema::MESSAGE_STATE] ?? '') === Schema::MESSAGE_STATE_SENT) {
                throw new \RuntimeException('The process died');
            }
        };
        try {
            $this->test();
            self::fail('The process died');
        } catch (\RuntimeException) {
        }
        self::items()->onWrite = null;
        [$msg]                 = array_keys(self::testItems());
        self::assertSame(Schema::MESSAGE_STATE_SENDING, self::items()->field($msg, Schema::MESSAGE_STATE));

        TestSendRun::$now = self::NOW + Client::CREATE_TIMEOUT + 60;
        self::assertSame(TestResult::BUSY, $this->test()->code);
        self::assertSame(Schema::MESSAGE_STATE_SENDING, self::items()->field($msg, Schema::MESSAGE_STATE));

        TestSendRun::$now = self::NOW + Client::CREATE_TIMEOUT + 61;
        $result           = $this->test();

        self::assertSame([TestResult::SAME_LETTER, $msg, '4711-T2', self::NOW], [$result->code, $result->msg, $result->ref, $result->at]);
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::items()->field($msg, Schema::MESSAGE_STATE));
        self::assertSame(Schema::EVENT_TIMEOUT, self::timeline($msg)[array_key_last(self::timeline($msg))]['kind']);
        self::assertSame(1, count($isds->sent()), 'never sent again by itself');
    }

    public function test_test_sameLetterSameBoxRefused(): void
    {
        $isds  = self::testIsds();
        $first = $this->test(self::testPage([self::TEST_BOX, self::TEST_BOX2]));

        $again = $this->test(self::testPage([self::TEST_BOX, self::TEST_BOX2]));
        $other = $this->test(self::testPage([self::TEST_BOX, self::TEST_BOX2]), self::TEST_BOX2);

        self::assertSame([TestResult::STATUS_REFUSED, TestResult::SAME_LETTER, $first->msg, $first->ref, $first->dmId, self::NOW], [$again->status, $again->code, $again->msg, $again->ref, $again->dmId, $again->at]);
        self::assertSame(TestResult::STATUS_SENT, $other->status, 'another box gets it');
        self::assertSame([self::TEST_BOX, self::TEST_BOX2], $isds->sent());
    }

    public function test_test_editedDraftMayTestAgain(): void
    {
        $isds = self::testIsds();
        $this->test();
        self::subjectIs('Pozvánka na sněm');

        $result = $this->test();

        self::assertSame(TestResult::STATUS_SENT, $result->status);
        self::assertSame(['ZKOUŠKA: Pozvánka', 'ZKOUŠKA: Pozvánka na sněm'], $isds->sent('dmAnnotation'));
    }

    // a refusal sends nothing: the same letter may go once what ISDS refused is fixed
    public function test_test_failedDoesNotBlock(): void
    {
        $isds  = self::testIsds(create: [new Response(200, self::fixture('create-message-1233.xml')), self::created('2002')]);
        $first = $this->test();

        $second = $this->test();

        self::assertSame([TestResult::STATUS_FAILED, '', '1233'], [$first->status, $first->code, $first->isdsCode]);
        self::assertStringContainsString('nekomerční', $first->detail);
        self::assertSame([Schema::MESSAGE_STATE_FAILED, '1233'], [self::items()->field($first->msg, Schema::MESSAGE_STATE), self::items()->field($first->msg, Schema::MESSAGE_ERROR_CODE)]);
        self::assertSame([TestResult::STATUS_SENT, '2002'], [$second->status, $second->dmId]);
        self::assertCount(2, $isds->sent());
    }

    // a double click, a second tab, F5 before the redirect, the same POST two minutes later
    public function test_test_samePostTwiceSendsOnce(): void
    {
        $isds  = self::testIsds();
        $first = $this->test();
        TestSendRun::$now = self::NOW + 120;

        $second = $this->test();

        self::assertSame([TestResult::STATUS_SENT, TestResult::SAME_LETTER], [$first->status, $second->code]);
        self::assertCount(1, $isds->sent());
        self::assertCount(1, self::testItems());
    }

    // a list of the files would not fit an entry of the timeline: the sending would fail before ISDS
    public function test_test_manyAttachmentsStillSend(): void
    {
        $isds = self::testIsds();
        $this->sending->files = [];
        for ($i = 1; $i < Message::MAX_FILES; ++$i) {
            $name = sprintf('%03d-', $i) . str_repeat('priloha-', 25) . '.pdf';
            file_put_contents("$this->dir/$name", self::PDF . $i);
            $this->sending->files[] = TestSendQueue::UPLOAD_URL . '/' . $name;
        }

        $result = $this->test();

        self::assertSame([TestResult::STATUS_SENT, ''], [$result->status, $result->detail]);
        $sending = self::timeline($result->msg)[1];
        self::assertSame([Schema::EVENT_SENDING, Message::MAX_FILES], [$sending['kind'], $sending['files']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', $sending['sum']);
        self::assertCount(Message::MAX_FILES, $this->sending->testLogged[0][3], 'each file logged');
        self::assertCount(1, $isds->sent());
    }

    // the item shows the message on its way, with what tells its letter, before the request leaves
    public function test_test_sendingBeforeTheRequest(): void
    {
        $seen = [];
        TestSendRun::$http = new ScriptedIsds(['PDZSendInfo' => [new Response(200, self::paidAnswer())], 'CreateMessage' => [static function () use (&$seen): Response {
            [$msg]  = array_keys(self::testItems());
            $last   = self::timeline($msg)[array_key_last(self::timeline($msg))];
            $seen[] = [self::items()->field($msg, Schema::MESSAGE_STATE), self::items()->field($msg, Schema::MESSAGE_SENDER_REF), $last['kind'], isset($last['sum']), $last['files'] ?? null];
            return self::created('2001');
        }]]);

        self::assertSame(TestResult::STATUS_SENT, $this->test()->status);

        self::assertSame([[Schema::MESSAGE_STATE_SENDING, '4711-T2', Schema::EVENT_SENDING, true, 2]], $seen);
    }

    // a failure AA does not know: the message may have gone - uncertain, and the caller learns of it
    public function test_test_unknownFailureUncertainAndThrown(): void
    {
        self::testIsds(create: [new \LogicException('A bug')]);

        try {
            $this->test();
            self::fail('The failure goes up');
        } catch (\LogicException $e) {
            self::assertSame('A bug', $e->getMessage());
        }

        [$msg] = array_keys(self::testItems());
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::items()->field($msg, Schema::MESSAGE_STATE));
        self::assertSame(['unlock isds-test:' . Sending::MESSAGE], array_slice($this->sending->lockLog, -1));
    }

    /** @return array<string, array{string}> */
    public static function editedStates(): array
    {
        return ['none' => [''], 'a word of no state' => ['odesláno'], 'stopped' => [Schema::MESSAGE_STATE_STOPPED]];
    }

    // only failed and queued sent nothing for sure: a state edited by hand may hide a letter that went
    #[DataProvider('editedStates')]
    public function test_test_editedStateBlocks(string $state): void
    {
        self::testIsds();
        $first = $this->test();
        self::items()->stored[$first->msg]['fields'][Schema::MESSAGE_STATE] = $state;

        self::assertSame(TestResult::SAME_LETTER, $this->test()->code);
    }

    // what a page shows of an earlier test: a reference or a message id edited into a text is none
    public function test_test_sameLetterEditedReferenceShowsNone(): void
    {
        self::testIsds();
        $first = $this->test();
        self::items()->stored[$first->msg]['fields'][Schema::MESSAGE_SENDER_REF] = '<b>4711-T2</b>';
        self::items()->stored[$first->msg]['fields'][Schema::MESSAGE_DM_ID]      = '2001<script>';

        $again = $this->test();

        self::assertSame([TestResult::SAME_LETTER, '', ''], [$again->code, $again->ref, $again->dmId]);
    }

    // a timeline edited by hand tells no letter: it may be this one
    public function test_test_unreadableTimelineBlocks(): void
    {
        self::testIsds();
        $first = $this->test();
        self::items()->stored[$first->msg]['fields'][Schema::MESSAGE_EVENTS] = '[{"at":"soon"';
        self::subjectIs('Pozvánka na sněm');

        self::assertSame(TestResult::SAME_LETTER, $this->test()->code);
    }

    public function test_test_trashedPreviousStillBlocks(): void
    {
        self::testIsds();
        $first = $this->test();
        self::items()->stored[$first->msg]['fields']['status_code.....'] = '3';

        self::assertSame(TestResult::SAME_LETTER, $this->test()->code);
    }

    // the recipient sees another name: another letter
    public function test_test_renamedFileIsOtherLetter(): void
    {
        $isds = self::testIsds();
        $this->test();
        rename("$this->dir/pozvanka.pdf", "$this->dir/pozvanka-2.pdf");
        $this->sending->files = [TestSendQueue::UPLOAD_URL . '/pozvanka-2.pdf'];

        self::assertSame(TestResult::STATUS_SENT, $this->test()->status);
        self::assertCount(2, $isds->sent());
    }

    // the letter is made anew for each test: the same draft, the same checksum
    public function test_test_sameDraftSameSum(): void
    {
        self::testIsds();
        $page = self::testPage([self::TEST_BOX, self::TEST_BOX2]);

        $first  = $this->test($page);
        $second = $this->test($page, self::TEST_BOX2);
        self::subjectIs('Pozvánka na sněm');
        $edited = $this->test($page, self::TEST_BOX2);

        $sums = array_map(static fn(TestResult $r): string => self::timeline($r->msg)[1]['sum'], [$first, $second, $edited]);
        self::assertSame($sums[0], $sums[1]);
        self::assertNotSame($sums[0], $sums[2]);
        self::assertSame($sums, array_column($this->sending->testLogged, 2), 'the log holds it too');
    }

    // a PDF of the same draft is not the same bytes twice: what the main file is made of counts, not its bytes
    public function test_test_sumWithoutMainBytes(): void
    {
        $isds = self::testIsds();
        $page = self::testPage([self::TEST_BOX, self::TEST_BOX2]);
        $this->sending->mainTail = '<!-- 1 -->';
        $first = $this->test($page);
        $this->sending->mainTail = '<!-- 2 -->';

        $second = $this->test($page, self::TEST_BOX2);
        $again  = $this->test($page);

        self::assertSame([TestResult::STATUS_SENT, TestResult::SAME_LETTER], [$second->status, $again->code]);
        self::assertSame(self::timeline($first->msg)[1]['sum'], self::timeline($second->msg)[1]['sum']);
        [$bytes1, $bytes2] = array_map(static fn(array $l): string => explode(':', $l[3][0])[2], $this->sending->testLogged);
        self::assertNotSame($bytes1, $bytes2, 'other bytes went');
        file_put_contents("$this->dir/pozvanka.pdf", self::PDF . '%');
        self::assertSame(TestResult::STATUS_SENT, $this->test($page)->status, 'an attachment counts by its bytes');
        self::assertCount(3, $isds->sent());
    }

    // what went can be found again: the bytes of each file, and what the main one was made of
    public function test_test_logHasBytesAndSource(): void
    {
        $isds = self::testIsds();
        $long = str_repeat('příloha-', 24) . '.pdf';
        file_put_contents("$this->dir/$long", self::PDF);
        $this->sending->files[]  = TestSendQueue::UPLOAD_URL . '/' . rawurlencode($long);
        $this->sending->mainTail = '<!-- made again -->';

        $this->test();

        $sent = self::sentLetter($isds->calls[1]['body']);
        $rows = $this->sending->testLogged[0][3];
        self::assertSame('1:' . strlen($sent) . ':' . hash('xxh128', $sent) . ':' . PdfBody::source($this->document()) . ':Dopis.pdf', $rows[0]);
        self::assertSame('2:' . strlen(self::PDF) . ':' . hash('xxh128', self::PDF) . ':pozvanka.pdf', $rows[1]);
        self::assertStringStartsWith('3:' . strlen(self::PDF) . ':' . hash('xxh128', self::PDF) . ':příloha-', $rows[2]);
        self::assertLessThanOrEqual(255, strlen($rows[2]), 'a long name cut');
        self::assertTrue(mb_check_encoding($rows[2], 'UTF-8'));
    }

    /** the letter of the campaign item as Letter::of() makes its document, with the letterhead of the page */
    private function document(): string
    {
        return (new HtmlBody(new UploadDir(TestSendQueue::UPLOAD_URL, $this->dir)))->document('Pozvánka', '<p>Vážení, <b>zveme</b> Vás</p>', true, "Národní síť\nPraha", PdfBody::CSS);
    }

    /** the main file of the message in the body of CreateMessage */
    private static function sentLetter(string $body): string
    {
        return base64_decode((string) preg_replace('~.*dmFileDescr="Dopis.pdf"[^>]*>\s*<\w+:dmEncodedContent>([^<]*)<.*~s', '$1', $body));
    }

    // a letter takes seconds to be made: the time of the request is raised for it, never cut
    #[DataProvider('timeLimits')]
    public function test_test_timeLimitOnlyRaised(int $limit, array $set): void
    {
        self::testIsds();
        $this->sending->timeLimit = $limit;

        self::assertSame(TestResult::STATUS_SENT, $this->test()->status);

        self::assertSame($set, $this->sending->timeLimits);
    }

    public static function timeLimits(): array
    {
        return [
            'none'   => [0, []],
            'short'  => [30, [60]],
            'longer' => [300, [300]],
        ];
    }

    // what dompdf takes is asked for before it starts: refused only when memory_limit stays below it
    public function test_test_memoryRefusedOnlyWhenCannotRaise(): void
    {
        self::testIsds();
        $need = PdfBody::memory($this->document());
        TestSendRun::$memoryLimit = 1024;

        $refused = $this->test();
        TestSendRun::$mayRaise = true;
        $raised                = $this->test();
        TestSendRun::$mayRaise    = false;
        TestSendRun::$memoryLimit = 8 * 1024 * 1024 * 1024;
        $enough                   = $this->test(box: self::TEST_BOX2, setup: self::testPage([self::TEST_BOX, self::TEST_BOX2]));

        self::assertSame([TestResult::STATUS_REFUSED, SendStart::MEMORY], [$refused->status, $refused->code]);
        self::assertGreaterThan($need, (int) substr($refused->detail, 3) * 1024 * 1024, 'the MB the PDF needs');
        self::assertSame(TestResult::STATUS_SENT, $raised->status);
        self::assertGreaterThan($need, $this->sending->raised[0] ?? 0, 'raised for the PDF first');
        self::assertSame(TestResult::STATUS_SENT, $enough->status);
    }

    public function test_test_campaignStateUnchanged(): void
    {
        self::testIsds();
        $before = TableDb::$tables;
        $fields = self::items()->stored[self::CAMPAIGN_ITEM]['fields'];

        self::assertSame(TestResult::STATUS_SENT, $this->test()->status);

        self::assertSame($fields, self::items()->stored[self::CAMPAIGN_ITEM]['fields']);
        self::assertSame($before, TableDb::$tables, 'no run, no snapshot, no step');
        self::assertSame([], array_values(array_diff(self::items()->written('update'), array_keys(self::testItems()))), 'only its message item written');
    }

    // ISDS took the request and never answered: the message may have gone - uncertain, by its reference
    public function test_test_uncertainOnTimeout(): void
    {
        $isds = self::testIsds(create: [new HttpTransportException('Operation timed out after 300000 milliseconds')]);

        $result = $this->test();

        self::assertSame([TestResult::STATUS_UNCERTAIN, '', '4711-T2'], [$result->status, $result->code, $result->ref]);
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::items()->field($result->msg, Schema::MESSAGE_STATE));
        self::assertSame(TestResult::SAME_LETTER, $this->test()->code, 'never sent again by itself');
        self::assertCount(1, $isds->sent());
    }

    // a login refused, an address blocked, ISDS down: nothing left, and a test is never tried again by itself
    public function test_test_nothingLeftIsFailed(): void
    {
        self::testIsds(create: [new HttpTransportException('No connection', 0, new \RuntimeException('Could not resolve host: ws1.czebox.cz')), self::created('2002')]);

        $result = $this->test();

        self::assertSame([TestResult::STATUS_FAILED, Outcome::UNAVAILABLE], [$result->status, $result->code]);
        self::assertSame([Schema::MESSAGE_STATE_FAILED, Outcome::UNAVAILABLE], [self::items()->field($result->msg, Schema::MESSAGE_STATE), self::items()->field($result->msg, Schema::MESSAGE_ERROR_CODE)]);
        self::assertSame(TestResult::STATUS_SENT, $this->test()->status, 'the same letter may go: none went');
    }

    // the letter as stored, the letterhead of the page and the prefix: nothing of the request but the box
    public function test_test_sendsStoredDraftNotRequest(): void
    {
        $isds = self::testIsds();

        $this->test();

        $body = $isds->calls[1]['body'];
        self::assertSame(['ZKOUŠKA: Pozvánka'], $isds->sent('dmAnnotation'));
        self::assertSame(['starosta'], $isds->sent('dmToHands'));
        self::assertStringContainsString('dmFileDescr="pozvanka.pdf"', $body);
        self::assertStringContainsString('<isds:dmEncodedContent>' . base64_encode(self::PDF) . '<', $body);
        self::assertSame("Národní síť\nPraha\nVážení, \nzveme\n Vás\n", PdfText::of(self::sentLetter($body)), 'the letterhead of the page and the draft');
    }

    public function test_test_insideATransaction_throws(): void
    {
        $this->expectException(\LogicException::class);

        DB_AA::transaction(fn(): TestResult => $this->test());
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
