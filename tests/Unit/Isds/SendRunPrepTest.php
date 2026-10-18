<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Credential\Secret;
use AA\Http\Response;
use AA\IO\DB\DB_AA;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\File;
use AA\Isds\Letter;
use AA\Isds\Recipient;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SliceProblem;
use AA\Isds\SliceRules;
use AA\Isds\Snapshot;
use AA_Credentials;
use AA_Perm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\MemorySendItems;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;
use Tests\Unit\Isds\Registry\Fixtures\PropertySlice;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Legacy\Fixtures\FakeFields;

// the in-memory object_text and toexecute, the config constants; the fixtures extend classes of AA
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/fixtures/Sending.php';
require_once __DIR__ . '/fixtures/MemorySendItems.php';
require_once __DIR__ . '/fixtures/MemoryLatch.php';
require_once __DIR__ . '/fixtures/TestSendRun.php';
require_once __DIR__ . '/fixtures/FakePerms.php';
require_once __DIR__ . '/../fixtures/FakeHttp.php';

/**
 * The run of a mass sending up to its first message: the record, the message items made from the
 * frozen recipients, the account checked before ISDS is asked anything - over TableDb's object_text
 * and toexecute, items in memory, ISDS answering from fixtures.
 */
final class SendRunPrepTest extends TestCase
{
    use BatchRunTables {
        setUpBeforeClass as private tablesSetUpBeforeClass;
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const CAMPAIGN_ITEM = 'ca000000000000000000000000000c01';
    /** the box owner-info.xml names */
    private const SENDER = 'kv62bqf';
    /** the position sending starts at, its keys sorted as position() gives them */
    private const SEND   = ['i' => 0, 'p' => 'send'];

    public static function setUpBeforeClass(): void
    {
        self::tablesSetUpBeforeClass();
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);      // AA::Pagecache(), which opening a slice keeps the page out of
        }
    }

    protected function setUp(): void
    {
        $this->tablesSetUp();
        TestSendRun::reset();
        TestSendRun::$now   = self::NOW;
        TestSendRun::$items = new MemorySendItems(self::CAMPAIGN_ITEM);
        // both have a reading password; no database
        foreach ([Sending::CAMPAIGN, Sending::MESSAGE] as $slice) {
            new PropertySlice($slice, new FakeFields($slice, []), ['reading_password' => md5('read')]);
        }
    }

    protected function tearDown(): void
    {
        TestSendRun::reset();
        FakePerms::uninstall();
        PropertySlice::forget(Sending::CAMPAIGN);
        PropertySlice::forget(Sending::MESSAGE);
        (new \ReflectionProperty(AA_Credentials::class, 'instance'))->setValue(null, null);
        (new \ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
        $this->tablesTearDown();
    }

    /**
     * A run as queueing a campaign leaves it, its snapshot saved with it
     * @param array<string, string> $props own properties over the queued ones
     * @param int $n recipients, the first one skipped without a box
     */
    private static function queued(array $position = ['p' => 'prep', 'i' => 0], array $props = [], int $n = 3): string
    {
        return DB_AA::transaction(static function () use ($position, $props, $n): string {
            $letter     = new Letter('Pozvánka', [new File('Dopis.html', 'text/html', File::MAIN, '<p>Vážení</p>')], 'starosta');
            $recipients = [new Recipient(self::src(1), 'Adamov', '', Schema::BOX_MISSING)];
            for ($i = 2; $i <= $n; ++$i) {
                $recipients[] = new Recipient(self::src($i), "Obec $i", sprintf('b%06d', $i), '');
            }
            $snapshot = Snapshot::of($letter, $recipients, self::CAMPAIGN_ITEM);
            $snapshot->save();
            return TestSendRun::start($props + [
                'subject'    => self::CAMPAIGN_ITEM,
                'user'       => Sending::USER,
                'position'   => $position,
                'site'       => Sending::SITE,
                'env'        => Account::TEST,
                'account'    => 'NSZM',
                'kind'       => Account::BASIC,
                'acct_id'    => self::fingerprint(),
                'sender_box' => self::SENDER,
                'camp_slice' => Sending::CAMPAIGN,
                'msg_slice'  => Sending::MESSAGE,
                'camp_short' => '7',
                'snapshot'   => (string) $snapshot->getId(),
                'manifest'   => $snapshot->manifest(),
                'cursor'     => '0',
            ])->getId();
        });
    }

    private static function src(int $i): string
    {
        return sprintf('b%031x', $i);
    }

    /** of the account Sending::bound() sets up, as the code that queues the run computes it */
    private static function fingerprint(): string
    {
        return TestSendRun::fingerprintOf(new Account(Account::TEST, 'nszm01login', new Secret('Test-Pa55-never-shown')));
    }

    /** owner-info.xml, the login of $box; $status other than 0000 refuses the question */
    private static function ownerXml(string $box = self::SENDER, string $status = '0000'): string
    {
        return str_replace(['<p:dbID>' . self::SENDER . '<', '<p:dbStatusCode>0000<'], ["<p:dbID>$box<", "<p:dbStatusCode>$status<"], (string) file_get_contents(__DIR__ . '/fixtures/owner-info.xml'));
    }

    /** ISDS answers whose the login is: $box */
    private static function ownerIs(string $box = self::SENDER): FakeHttp
    {
        return TestSendRun::$http = new FakeHttp([new Response(200, self::ownerXml($box))]);
    }

    private static function items(): MemorySendItems
    {
        return TestSendRun::$items ?? throw new \LogicException('No items');
    }

    /** @return list<string> the message ids frozen in the run's snapshot */
    private static function messages(string $id): array
    {
        $stored = self::stored($id);
        return array_column(Snapshot::read($stored['snapshot'], $stored['manifest'], false, self::CAMPAIGN_ITEM)->recipients(), 'msg');
    }

    /** @return array<string, int|string> the stored position, its keys sorted */
    private static function position(string $id): array
    {
        $position = json_decode(self::stored($id)['position'], true);
        ksort($position);
        return $position;
    }

    /** @return list<array<string, int|string>> the timeline of the item $id */
    private static function timeline(string $id): array
    {
        return json_decode(self::items()->field($id, Schema::MESSAGE_EVENTS), true);
    }

    /** a part of the run's snapshot as another process damaged it */
    private static function damage(string $id, string $part): void
    {
        self::storedSet(self::stored($id)['snapshot'], $part, base64_encode('[]'));
    }

    /** the run as a step left it that died holding its lease */
    private static function diedHolding(string $id, int $failures): void
    {
        self::storedSet($id, 'lease', (string) (self::NOW - 1));
        self::storedSet($id, 'lease_tok', 'dead-step');
        self::storedSet($id, 'failures', (string) $failures);
    }

    private static function role(string $role): string
    {
        return AA_Perm::ROLES[$role]['id'];
    }

    // ------------------------------------------------------------ prep

    public function test_work_prep_makes_an_item_per_recipient(): void
    {
        $id = self::queued();

        self::assertSame('step 1', self::runQueued());

        $items    = self::items();
        $messages = self::messages($id);
        self::assertSame($messages, $items->written('insert_if_new'), 'in the order frozen');
        self::assertSame([Schema::MESSAGE_STATE_SKIPPED, Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_QUEUED], array_map(static fn(string $m): string => $items->field($m, Schema::MESSAGE_STATE), $messages));
        self::assertSame([Schema::BOX_MISSING, Account::TEST, self::SENDER, self::src(1)], [
            $items->field($messages[0], Schema::MESSAGE_ERROR_CODE), $items->field($messages[0], Schema::MESSAGE_ENVIRONMENT),
            $items->field($messages[0], Schema::MESSAGE_SENDER_BOX), $items->field($messages[0], Schema::MESSAGE_RECIPIENT),
        ]);
        self::assertSame([true, true, true], array_column(array_slice($items->writes, 0, 3), 'tx'), 'in a transaction');
        self::assertSame(Schema::CAMPAIGN_STATE_SENDING, $items->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_STATE));
        self::assertSame([Schema::CAMPAIGN_STATE_SENDING, 'sending'], [self::stored($id)['camp_state'], self::stored($id)['state']]);
        self::assertSame(self::SEND, self::position($id));
        self::assertSame(1, TestSendRun::$invalidated, 'the pages of the message slice once a step');
    }

    public function test_work_prep_writes_the_environment_queued(): void
    {
        $id = self::queued(props: ['env' => Account::PRODUCTION]);

        self::runQueued();

        self::assertSame([Account::PRODUCTION], array_values(array_unique(array_map(static fn(string $m): string => self::items()->field($m, Schema::MESSAGE_ENVIRONMENT), self::messages($id)))));
    }

    public function test_work_creates_items_once_after_dying(): void
    {
        $id   = self::queued();
        $died = null;
        // the process dies after the items are stored, before the step writes its end
        self::items()->onWrite = static function () use (&$died): void {
            $died ??= TableDb::$tables;
        };
        self::runQueued();
        TableDb::$tables       = $died ?? throw new \LogicException('No write');
        self::items()->onWrite = null;
        TestSendRun::$now      = (int) self::stored($id)['lease'] + 1;

        self::assertSame('step 1', self::runQueued(), 'the queue repeats the step');

        $messages = self::messages($id);
        self::assertSame([...$messages, ...$messages], self::items()->written('insert_if_new'));
        foreach ($messages as $message) {
            self::assertSame([self::NOW], array_column(self::timeline($message), 'at'), 'as the first step made it');
        }
        self::assertSame(self::SEND, self::position($id));
    }

    public function test_work_prep_goes_on_where_time_ran_out(): void
    {
        $id                    = self::queued(n: 120);
        TestSendRun::$microNow = (float) self::NOW;
        self::items()->onWrite = static function (): void {
            TestSendRun::$microNow += 1.0;
        };

        self::runQueued();

        self::assertSame(['i' => 50, 'p' => 'prep'], self::position($id), 'the budget is spent after a chunk');
        self::assertCount(50, self::items()->written('insert_if_new'));
        self::assertSame(1, TestSendRun::$invalidated);
        self::assertSame('', self::stored($id)['camp_state']);

        self::runQueued();
        self::runQueued();

        self::assertSame(self::messages($id), self::items()->written('insert_if_new'), 'each once, from where the step before stopped');
        self::assertSame(self::SEND, self::position($id));
    }

    // Continue after an error in prep: the campaign is sent again, not left showing the error
    public function test_work_prep_after_continue_shows_sending(): void
    {
        $id = self::queued(['p' => 'prep', 'i' => 50], ['camp_state' => Schema::CAMPAIGN_STATE_ERROR], 120);
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'] = [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_ERROR, Schema::CAMPAIGN_ERROR_TEXT => Snapshot::CHANGED];
        TestSendRun::$microNow = (float) self::NOW;
        self::items()->onWrite = static function (): void {
            TestSendRun::$microNow += 1.0;
        };

        self::runQueued();

        self::assertSame(['i' => 100, 'p' => 'prep'], self::position($id), 'still preparing');
        self::assertSame([Schema::CAMPAIGN_STATE_SENDING, ''], [self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_STATE), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
        self::assertSame(Schema::CAMPAIGN_STATE_SENDING, self::stored($id)['camp_state']);
    }

    public function test_work_prep_needs_no_account(): void
    {
        $id = self::queued();
        // no account bound, and no ISDS: TestSendRun::client() throws

        self::assertSame('step 1', self::runQueued());

        self::assertSame(self::SEND, self::position($id));
        self::assertCount(3, self::items()->written('insert_if_new'));
    }

    // only sending needs the letter: prep does not read it, the first step of sending finds it changed
    public function test_work_prep_reads_no_file(): void
    {
        $id = self::queued();
        self::damage($id, 'files');
        TestSendRun::$constants = Sending::bound();
        self::ownerIs();

        self::assertSame('step 1', self::runQueued());
        self::assertSame('error ' . Snapshot::CHANGED, self::runQueued());
    }

    // both open for the whole step, not just for each write; the queue runs the next task in this
    // process, and it must find closed what the step opened
    #[DataProvider('stepEnds')]
    public function test_work_closes_only_slices_it_opened(bool $fails): void
    {
        AA_Credentials::singleton()->openForRequest(Sending::MESSAGE);
        self::queued();
        if ($fails) {
            self::items()->onWrite = static fn() => throw new \RuntimeException('not stored');
        }

        self::assertSame($fails ? 'error INTERNAL' : 'step 1', self::runQueued());

        self::assertSame([[Sending::CAMPAIGN => true, Sending::MESSAGE => true]], array_values(array_unique(array_column(self::items()->writes, 'open'), SORT_REGULAR)));
        self::assertTrue(AA_Credentials::singleton()->checkSlice(Sending::MESSAGE), 'open before, open after');
        self::assertFalse(AA_Credentials::singleton()->checkSlice(Sending::CAMPAIGN));
        self::assertSame(1, TestSendRun::$invalidated, 'what the step stored before it failed is shown');
    }

    // a run lasts hours: a template, a feed or a password taken off since it was queued
    public function test_work_fails_when_slices_break_a_rule(): void
    {
        $id    = self::queued();
        $rules = new class extends SliceRules {
            /** @var list<array{string, string, bool}> the slices asked about, and whether they were open then */
            public array $asked = [];
            /** @var list<SliceProblem> */
            public array $problems = [];

            public function sliceProblems(string $campaignSlice, string $messageSlice): array
            {
                $this->asked[] = [$campaignSlice, $messageSlice, AA_Credentials::singleton()->checkSlice($messageSlice)];
                return $this->problems;
            }
        };
        TestSendRun::$rules = $rules;
        self::assertSame('step 1', self::runQueued());
        // a step died sending message 1
        self::storedSet($id, 'inflight', '1');
        self::storedSet($id, 'inflight_at', (string) (self::NOW - 50));
        $writes                 = count(self::items()->writes);
        $rules->problems        = [new SliceProblem('Slice "ISDS messages" imports items from other slices')];
        TestSendRun::$constants = Sending::bound();
        $http                   = self::ownerIs();

        self::assertSame('error ' . SendRun::SETTINGS, self::runQueued());

        self::assertSame([[Sending::CAMPAIGN, Sending::MESSAGE, false], [Sending::CAMPAIGN, Sending::MESSAGE, false]], $rules->asked, 'every step, before it opens them');
        self::assertCount($writes, self::items()->writes, 'not even the error into the campaign');
        self::assertSame([], $http->calls);
        self::assertSame(self::SEND, self::position($id));
        $stored = self::stored($id);
        self::assertSame(['1', '0', ''], [$stored['inflight'], $stored['cursor'], $stored['uncertain']], 'the next step settles it');
    }

    /** @return array<string, array{bool}> */
    public static function stepEnds(): array
    {
        return ['the step ends' => [false], 'the step fails' => [true]];
    }

    // ------------------------------------------------------------ what a step that died left

    #[DataProvider('accounts')]
    public function test_work_resolves_inflight_as_uncertain(array $constants, string $ends): void
    {
        $id = self::queued();
        self::runQueued();
        $messages = self::messages($id);
        // a step sent message 1 and died before it learnt what became of it
        self::storedSet($id, 'inflight', '1');
        self::storedSet($id, 'inflight_at', (string) (self::NOW - 50));
        self::storedSet($id, 'uncertain', '{"0":100}');
        self::storedSet($id, 'cursor', '1');
        TestSendRun::$constants = $constants;
        self::ownerIs();
        TestSendRun::$now = self::NOW + 10;

        self::assertSame($ends, self::runQueued());

        $stored = self::stored($id);
        self::assertSame(['', '', '2'], [$stored['inflight'], $stored['inflight_at'], $stored['cursor']]);
        self::assertSame(['0' => 100, '1' => self::NOW - 50], json_decode($stored['uncertain'], true), 'the time it may have gone - the lookup searches from there');
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::items()->field($messages[1], Schema::MESSAGE_STATE));
        self::assertSame(['at' => self::NOW + 10, 'kind' => Schema::EVENT_TIMEOUT], array_slice(self::timeline($messages[1]), -1)[0]);
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::items()->field($messages[2], Schema::MESSAGE_STATE));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function accounts(): array
    {
        return [
            'the account as queued' => [Sending::bound(), 'step 2'],
            'the account gone'      => [[], 'error ACCOUNT_CHANGED'],
        ];
    }

    // the cursor only grows: a message tried later stays tried
    public function test_work_inflight_keeps_a_later_cursor(): void
    {
        $id                     = self::queued(self::SEND, ['cursor' => '3', 'inflight' => '1', 'inflight_at' => (string) (self::NOW - 50)]);
        TestSendRun::$constants = Sending::bound();
        self::ownerIs();

        self::runQueued();

        self::assertSame(['3', ''], [self::stored($id)['cursor'], self::stored($id)['inflight']]);
    }

    // End or Continue between the core's check of the lease and the first record of the step
    public function test_work_does_nothing_more_when_its_run_is_taken(): void
    {
        $id = self::queued();
        self::runQueued();
        $writes = count(self::items()->writes);
        self::storedSet($id, 'inflight', '1');
        self::storedSet($id, 'inflight_at', (string) (self::NOW - 50));
        TestSendRun::$constants = Sending::bound();
        $http                   = self::ownerIs();
        $armed                  = false;
        TableDb::$fail          = static function (string $sql) use ($id, &$armed): bool {
            if ($armed && str_contains($sql, 'FOR UPDATE')) {
                $armed = false;
                self::storedSet($id, 'state', 'error');
            }
            return false;
        };
        TestSendRun::$beforeWork = static function () use (&$armed): void {
            $armed = true;
        };

        self::runQueued();

        self::assertSame(['', '{"1":' . (self::NOW - 50) . '}'], [self::stored($id)['inflight'], self::stored($id)['uncertain']], 'recorded all the same');
        self::assertCount($writes, self::items()->writes, 'no item written');
        self::assertSame([], $http->calls, 'nor ISDS asked');
    }

    // a step that kept dying gets commitStep() with an empty result - the record keeps where sending is
    public function test_step_died_keeps_cursor(): void
    {
        $id = self::queued(self::SEND, ['cursor' => '2', 'inflight' => '2', 'inflight_at' => (string) (self::NOW - 600), 'uncertain' => '{"1":5}']);
        self::diedHolding($id, 2);

        self::assertSame('error STEP_DIED', self::runQueued());

        $stored = self::stored($id);
        self::assertSame(['2', '2', '{"1":5}'], [$stored['cursor'], $stored['inflight'], $stored['uncertain']], 'Continue finds the message in flight');
        self::assertSame([], self::items()->writes);
    }

    // a step that only pauses, or fails on the account, holds no attachment in memory - and marks what was in flight
    #[DataProvider('pastTheAccount')]
    public function test_work_reads_files_only_past_the_account(array $constants, string $answer, string $ends): void
    {
        $id = self::queued();
        self::runQueued();
        self::damage($id, 'files');
        self::storedSet($id, 'inflight', '1');
        self::storedSet($id, 'inflight_at', (string) (self::NOW - 50));
        TestSendRun::$constants = $constants;
        TestSendRun::$http      = new FakeHttp([($answer === '') ? new Response(503, 'Service Unavailable') : new Response(200, $answer)]);

        self::assertSame($ends, self::runQueued());

        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::items()->field(self::messages($id)[1], Schema::MESSAGE_STATE));
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function pastTheAccount(): array
    {
        return [
            'the account gone'   => [[], '', 'error ACCOUNT_CHANGED'],
            'ISDS not answering' => [Sending::bound(), '', 'step 2'],
            'the account fine'   => [Sending::bound(), self::ownerXml(), 'error ' . Snapshot::CHANGED],
        ];
    }

    // ------------------------------------------------------------ the campaign item

    // the code only: the text of an exception may name the file of a key
    #[DataProvider('failures')]
    public function test_work_failure_shows_on_the_campaign(array $position, string $damaged, string $answer, string $code): void
    {
        $id = self::queued($position);
        if ($damaged !== '') {
            self::damage($id, $damaged);
        }
        TestSendRun::$constants = ($answer === '') ? [] : Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(200, $answer)]);

        self::assertSame("error $code", self::runQueued());

        self::assertSame([Schema::CAMPAIGN_STATE_ERROR, $code], [self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_STATE), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
        self::assertSame(Schema::CAMPAIGN_STATE_ERROR, self::stored($id)['camp_state']);
    }

    /** @return array<string, array{array<string, int|string>, string, string, string}> */
    public static function failures(): array
    {
        return [
            'the account gone, sending'         => [self::SEND, '', '', 'ACCOUNT_CHANGED'],
            'the recipients changed, preparing' => [['p' => 'prep', 'i' => 0], 'rcpt', '', Snapshot::CHANGED],
            'ISDS refusing to name the owner'   => [self::SEND, '', self::ownerXml(status: '1214'), 'ISDS_REFUSED'],
        ];
    }

    // a step another one replaced would show its error over the other's sending; a slow one shows its own
    #[DataProvider('takenMidStep')]
    public function test_work_campaign_only_from_step_owning_run(string $property, string $value, bool $shown): void
    {
        $id    = self::queued(self::SEND);
        $armed = false;
        // the snapshot is read after the step's claim; no account is bound, so the step fails after it
        TableDb::$fail = static function (string $sql) use ($id, $property, $value, &$armed): bool {
            if ($armed && str_contains($sql, 'rcpt')) {
                $armed = false;
                self::storedSet($id, $property, $value);
            }
            return false;
        };
        TestSendRun::$beforeWork = static function () use (&$armed): void {
            $armed = true;
        };

        self::runQueued();

        $shows = $shown ? [Schema::CAMPAIGN_STATE_ERROR, SendRun::ACCOUNT_CHANGED] : [Schema::CAMPAIGN_STATE_QUEUED, ''];
        self::assertSame($shows, [self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_STATE), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function takenMidStep(): array
    {
        return [
            'claimed by another step'   => ['lease_tok', 'another-step', false],
            'ended'                     => ['state', 'stopped', false],
            'past its lease, not taken' => ['lease', (string) (self::NOW - 1), true],
        ];
    }

    public function test_work_failure_kept_when_campaign_cannot_be_written(): void
    {
        $id                    = self::queued(self::SEND, ['camp_state' => Schema::CAMPAIGN_STATE_SENDING]);
        self::items()->onWrite = static fn() => throw new \RuntimeException('not stored');

        self::assertSame('error ACCOUNT_CHANGED', self::runQueued());

        self::assertSame(Schema::CAMPAIGN_STATE_SENDING, self::stored($id)['camp_state']);
    }

    public function test_work_campaign_sending_again_after_continue(): void
    {
        $id = self::queued(self::SEND, ['camp_state' => Schema::CAMPAIGN_STATE_ERROR]);
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'] = [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_ERROR, Schema::CAMPAIGN_ERROR_TEXT => 'ACCOUNT_CHANGED'];
        TestSendRun::$constants = Sending::bound();
        self::ownerIs();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([Schema::CAMPAIGN_STATE_SENDING, ''], [self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_STATE), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
        self::assertSame(Schema::CAMPAIGN_STATE_SENDING, self::stored($id)['camp_state']);
    }

    // ------------------------------------------------------------ the account, before ISDS is asked anything

    #[DataProvider('bindings')]
    public function test_work_stops_when_binding_changed(array $constants, array $props): void
    {
        $id                     = self::queued(self::SEND, $props);
        TestSendRun::$constants = $constants;
        $http                   = self::ownerIs();

        self::assertSame('error ACCOUNT_CHANGED', self::runQueued());

        self::assertSame([], $http->calls);
        self::assertSame(self::fingerprint(), self::stored($id)['acct_id']);
    }

    /** @return array<string, array{array<string, string>, array<string, string>}> */
    public static function bindings(): array
    {
        $renamed = [];
        foreach (Sending::bound() as $name => $value) {
            $renamed[str_replace('_NSZM_', '_OTHER_', $name)] = $value;
        }
        return [
            'no account bound'           => [[], []],
            'another account'            => [$renamed, []],
            'not for the site any more'  => [Sending::bound(Sending::SITE2), []],
            'another pair of slices'     => [Sending::bound(Sending::SITE, Sending::CAMPAIGN . ',' . Sending::OTHER), []],
            'queued with a certificate'  => [Sending::bound(), ['kind' => Account::CERT]],
            'queued for production'      => [Sending::bound(), ['env' => Account::PRODUCTION]],
            'queued without environment' => [Sending::bound(), ['env' => '']],
        ];
    }

    // the run's account still bound to the slices, only broken: fixed in config.php, not a sender gone
    #[DataProvider('brokenBindings')]
    public function test_work_fails_on_broken_binding(array $constants, array $props, string $code): void
    {
        self::queued(self::SEND, $props);
        TestSendRun::$constants = $constants;
        $http                   = self::ownerIs();

        self::assertSame("error $code", self::runQueued());

        self::assertSame([], $http->calls);
    }

    /** @return array<string, array{array<string, string>, array<string, string>, string}> */
    public static function brokenBindings(): array
    {
        $broken  = array_diff_key(Sending::bound(), ['AA_APIKEY_ISDSTEST_NSZM_PASSWORD' => '']);
        $renamed = [];
        foreach ($broken as $name => $value) {
            $renamed[str_replace('_NSZM_', '_OTHER_', $name)] = $value;
        }
        return [
            'the account of the run'          => [$broken, [], 'ISDS_CONFIG'],
            'another account'                 => [$renamed, [], 'ACCOUNT_CHANGED'],
            'the run queued with certificate' => [$broken, ['kind' => Account::CERT], 'ACCOUNT_CHANGED'],
        ];
    }

    // a new password of the same box: a login of its own, checked once against ISDS
    public function test_work_accepts_new_fingerprint_of_same_box(): void
    {
        // the only recipient skipped: ISDS is asked nothing but whose the login is
        $id                     = self::queued(self::SEND, ['acct_id' => 'of the old password', 'owner_at' => (string) (self::NOW - 60)], 1);
        TestSendRun::$constants = Sending::bound();
        $http                   = self::ownerIs();

        self::assertSame('step 1', self::runQueued());

        self::assertCount(1, $http->calls);
        self::assertStringEndsWith('/DS/DsManage', $http->calls[0]['url']);
        self::assertSame([self::fingerprint(), (string) self::NOW, 'sending'], [self::stored($id)['acct_id'], self::stored($id)['owner_at'], self::stored($id)['state']]);
    }

    public function test_work_stops_when_box_changed(): void
    {
        $id                     = self::queued(self::SEND, ['acct_id' => 'of another login']);
        TestSendRun::$constants = Sending::bound();
        self::ownerIs('zz99zzz');

        self::assertSame('error ACCOUNT_CHANGED', self::runQueued());

        self::assertSame('of another login', self::stored($id)['acct_id']);
    }

    #[DataProvider('ownerChecks')]
    public function test_work_asks_owner_box_once_an_hour(string $ownerAt, int $calls): void
    {
        $id                     = self::queued(self::SEND, ['owner_at' => $ownerAt], 1);
        TestSendRun::$constants = Sending::bound();
        $http                   = self::ownerIs();

        self::runQueued();

        self::assertCount($calls, $http->calls);
        self::assertSame($calls ? (string) self::NOW : $ownerAt, self::stored($id)['owner_at']);
    }

    /** @return array<string, array{string, int}> */
    public static function ownerChecks(): array
    {
        return [
            'the first step of sending' => ['', 1],
            'a minute ago'              => [(string) (self::NOW - 60), 0],
            'a second short of an hour' => [(string) (self::NOW - 3599), 0],
            'an hour ago'               => [(string) (self::NOW - 3600), 1],
        ];
    }

    #[DataProvider('refusals')]
    public function test_work_stops_on_refused_login(string $page, string $code): void
    {
        $id                     = self::queued(self::SEND, ['owner_at' => '']);
        TestSendRun::$constants = Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(401, (string) file_get_contents(__DIR__ . "/fixtures/$page"))]);

        self::assertSame("error $code", self::runQueued());

        self::assertSame('', self::stored($id)['owner_at']);
    }

    /** @return array<string, array{string, string}> */
    public static function refusals(): array
    {
        return ['login refused' => ['unauthorized.html', 'ISDS_LOGIN'], 'address blocked' => ['address-blocked.html', 'ISDS_BLOCKED']];
    }

    // what ISDS said stays out of the record: its status is enough to look it up
    public function test_work_stops_when_isds_refuses_owner_info(): void
    {
        $id                     = self::queued(self::SEND);
        TestSendRun::$constants = Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(200, str_replace('Provedeno úspěšně.', 'Přístup odepřen', self::ownerXml(status: '1214')))]);

        self::assertSame('error ISDS_REFUSED', self::runQueued());

        $stored = self::stored($id);
        self::assertSame(['ISDS_REFUSED', 'status:1214'], [$stored['error_code'], $stored['error_detail']]);
        self::assertStringNotContainsString('odep', json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    // no answer is no reason to fail: the run waits, longer each time, and spends none of its retries
    public function test_work_pauses_while_isds_does_not_answer(): void
    {
        $id                     = self::queued(self::SEND);
        TestSendRun::$constants = Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(503, 'Service Unavailable')]);

        self::runQueued();
        TestSendRun::$now = self::NOW + 30;
        self::runQueued();

        $stored = self::stored($id);
        self::assertSame(['i' => 0, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 90, 'w' => 2], self::position($id));
        self::assertSame(['ISDS_UNAVAILABLE', (string) (self::NOW + 90), '0', 'sending'], [$stored['retry_code'], $stored['next_at'], $stored['failures'], $stored['state']]);
    }

    #[DataProvider('pauses')]
    public function test_work_pause_doubles_up_to_ten_minutes(int $waits, int $wait): void
    {
        $id                     = self::queued(['p' => 'send', 'i' => 0, 'w' => $waits, 'u' => self::NOW, 's' => self::NOW - 60]);
        TestSendRun::$constants = Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(503, 'Service Unavailable')]);

        self::runQueued();

        self::assertSame(['i' => 0, 'p' => 'send', 's' => self::NOW - 60, 'u' => self::NOW + $wait, 'w' => $waits + 1], self::position($id));
    }

    /** @return array<string, array{int, int}> SendPaceTest has the rest */
    public static function pauses(): array
    {
        return ['the tenth' => [9, 600]];
    }

    #[DataProvider('longPauses')]
    public function test_work_fails_after_six_hours_without_answer(int $since, string $ends): void
    {
        self::queued(['p' => 'send', 'i' => 0, 'w' => 9, 'u' => self::NOW, 's' => self::NOW - $since]);
        TestSendRun::$constants = Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(503, 'Service Unavailable')]);

        self::assertSame($ends, self::runQueued());
    }

    /** @return array<string, array{int, string}> SendPaceTest has the rest */
    public static function longPauses(): array
    {
        return ['a second more than six hours' => [6 * 3600 + 1, 'error ISDS_UNAVAILABLE']];
    }

    public function test_positionOnResume_resets_pause(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('EDITOR')]]);
        $id                     = self::queued(self::SEND);
        TestSendRun::$constants = Sending::bound();
        TestSendRun::$http      = new FakeHttp([new Response(503, 'Service Unavailable')]);
        self::runQueued();

        DB_AA::transaction(static fn() => TestSendRun::lockForUpdate($id)?->resume(Sending::USER));

        self::assertSame(self::SEND, self::position($id), 'Continue gives the full time again');
    }

    #[DataProvider('delays')]
    public function test_retryDelay_waits_out_the_pause_then_a_minute_per_failure(array $position, int $failures, int $delay): void
    {
        $id = self::queued($position);

        self::assertSame($delay, TestSendRun::get($id)?->retryDelayOf($failures));
    }

    /** @return array<string, array{array<string, int|string>, int, int}> */
    public static function delays(): array
    {
        return [
            'paused'         => [['p' => 'send', 'i' => 0, 'w' => 1, 'u' => self::NOW + 30, 's' => self::NOW], 0, 30],
            'pause over'     => [['p' => 'send', 'i' => 0, 'w' => 1, 'u' => self::NOW - 5, 's' => self::NOW], 0, 1],
            'no pause ends so far ahead' => [['p' => 'send', 'i' => 0, 'w' => 1, 'u' => self::NOW + 86_400, 's' => self::NOW], 0, 1],
            'not paused'     => [self::SEND, 0, 1],
            'second failure' => [self::SEND, 2, 120],
        ];
    }

    // a send step may wait for CreateMessage as long as the client lets it - cut short, it is taken for dead
    public function test_overrun_outlasts_create_message(): void
    {
        $id = self::queued(self::SEND);

        self::assertGreaterThan(Client::TIMEOUT + Client::CREATE_TIMEOUT, (int) TestSendRun::get($id)?->overrunOf(), 'the check of a box and the request');
    }

    // the other phases ask ISDS nothing: a step of theirs that died must not hold Continue back for minutes
    #[DataProvider('phasesWithoutIsds')]
    public function test_overrun_of_a_step_without_isds_is_short(array $position): void
    {
        $id                      = self::queued($position);
        $overrun                 = (int) TestSendRun::get($id)?->overrunOf();
        $lease                   = 0;
        TestSendRun::$beforeWork = static function () use ($id, &$lease): void {
            $lease = (int) self::stored($id)['lease'];
        };

        self::runQueued();

        self::assertLessThan(Client::CREATE_TIMEOUT, $overrun);
        self::assertGreaterThanOrEqual(self::NOW + $overrun, $lease, 'the lease of the step counts it');
    }

    /** @return array<string, array{array<string, int|string>}> */
    public static function phasesWithoutIsds(): array
    {
        return ['prep' => [['p' => 'prep', 'i' => 0]], 'stop' => [['p' => 'stop', 'i' => 0]], 'no phase, as prep' => [[]]];
    }

    // ------------------------------------------------------------ the run among others

    public function test_selector_names_the_campaign(): void
    {
        $id = self::queued();

        self::assertSame('IsdsSend_' . self::CAMPAIGN_ITEM, TestSendRun::get($id)?->selector());
        self::assertSame(['IsdsSend_' . self::CAMPAIGN_ITEM], array_column(self::queue(), 'selector'));
    }

    public function test_existsFor_any_run_of_the_campaign(): void
    {
        $id = self::queued();
        DB_AA::transaction(static function () use ($id): void {
            FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('EDITOR')]]);
            TestSendRun::lockForUpdate($id)?->end(Sending::USER);
        });

        self::assertTrue(TestSendRun::existsFor(self::CAMPAIGN_ITEM), 'ended too: a campaign is sent once');
        self::assertFalse(TestSendRun::existsFor('ca000000000000000000000000000c02'));
    }

    public function test_mayControl_who_may_edit_all_campaigns(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('EDITOR')]]);

        self::assertTrue(TestSendRun::get(self::queued())?->mayControl(Sending::USER));
    }

    #[DataProvider('strangers')]
    public function test_mayControl_refuses_others(string $uid): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('AUTHOR'), Sending::MESSAGE => self::role('EDITOR')], 'nobody' => [Sending::CAMPAIGN => self::role('EDITOR')]]);

        self::assertFalse(TestSendRun::get(self::queued())?->mayControl($uid));
    }

    /** @return array<string, array{string}> */
    public static function strangers(): array
    {
        return [
            'an author, editor of the messages' => [Sending::USER],
            'a user without a right'            => [Sending::READER],
            'nobody'                            => ['nobody'],
            'no login'                          => [''],
        ];
    }
}
