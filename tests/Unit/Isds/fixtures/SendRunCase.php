<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA;
use AA\Credential\Secret;
use AA\Http\Response;
use AA\IO\DB\DB_AA;
use AA\Isds\Account;
use AA\Isds\File;
use AA\Isds\Letter;
use AA\Isds\Recipient;
use AA\Isds\Schema;
use AA\Isds\Snapshot;
use AA_Credentials;
use AA_Perm;
use Tests\Unit\Isds\Registry\Fixtures\PropertySlice;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Legacy\Fixtures\FakeFields;

// the in-memory object_text and toexecute, the config constants; the fixtures extend classes of AA
require_once __DIR__ . '/../../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/Sending.php';
require_once __DIR__ . '/MemorySendItems.php';
require_once __DIR__ . '/MemoryLatch.php';
require_once __DIR__ . '/TestSendRun.php';
require_once __DIR__ . '/FakePerms.php';
require_once __DIR__ . '/ScriptedIsds.php';

/**
 * A run of a mass sending over TableDb's object_text and toexecute, its items in memory, ISDS
 * answering each operation from a script, Sending::USER an editor of the campaign slice - and the
 * ways a test reads them.
 */
trait SendRunCase
{
    use BatchRunTables {
        setUpBeforeClass as private tablesSetUpBeforeClass;
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const CAMPAIGN_ITEM = 'ca000000000000000000000000000c01';
    private const SENDER        = 'kv62bqf';
    /** the short id of the campaign: its messages are referenced 4711-1, 4711-2, ... */
    private const SHORT = '4711';
    /** positions, their keys sorted as position() gives them */
    private const SEND = ['i' => 0, 'p' => 'send'];
    private const LOOK = ['i' => 0, 'p' => 'look'];

    public static function setUpBeforeClass(): void
    {
        self::tablesSetUpBeforeClass();
        require_once __DIR__ . '/../Registry/fixtures/PropertySlice.php';
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);      // AA::Pagecache(), which opening a slice keeps the page out of
        }
    }

    protected function setUp(): void
    {
        $this->tablesSetUp();
        TestSendRun::reset();
        TestSendRun::$now       = self::NOW;
        TestSendRun::$items     = new MemorySendItems(self::CAMPAIGN_ITEM);
        TestSendRun::$constants = Sending::bound();
        foreach ([Sending::CAMPAIGN, Sending::MESSAGE] as $slice) {
            new PropertySlice($slice, new FakeFields($slice, []), ['reading_password' => md5('read')]);
        }
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]]);
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
     * A run queued to send to $n recipients, the campaign shown sending, its message items made by
     * prep, ISDS asked whose the login is a moment ago
     * @param array<string, string>     $props    own properties over the queued ones
     * @param array<string, int|string> $position
     * @param array<int, string>        $reasons  index => the Schema::BOX_PROBLEMS a recipient was frozen skipped for
     * @param ?int                      $made     the message items prep made, null = all
     */
    private static function queued(int $n = 3, array $props = [], array $position = self::SEND, array $reasons = [], ?int $made = null, ?Letter $letter = null): string
    {
        $now = (int) TestSendRun::$now;
        $id  = DB_AA::transaction(static function () use ($n, $reasons, $props, $position, $letter, $now): string {
            $recipients = [];
            for ($i = 0; $i < $n; ++$i) {
                $recipients[] = new Recipient(sprintf('b%031x', $i), "Obec $i", self::box($i), $reasons[$i] ?? '');
            }
            $snapshot = Snapshot::of($letter ?? self::letter(), $recipients, self::CAMPAIGN_ITEM);
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
                'camp_short' => self::SHORT,
                'snapshot'   => (string) $snapshot->getId(),
                'manifest'   => $snapshot->manifest(),
                'cursor'     => '0',
                'owner_at'   => (string) $now,
                'camp_state' => Schema::CAMPAIGN_STATE_SENDING,
            ])->getId();
        });
        foreach (array_slice(self::rows($id), 0, $made) as $row) {
            self::items()->create($row, Account::TEST, self::SENDER, $now);
        }
        self::items()->writes = [];
        return $id;
    }

    private static function letter(string $attachment = ''): Letter
    {
        $files = [new File('Dopis.html', 'text/html', File::MAIN, '<p>Vážení</p>')];
        if ($attachment !== '') {
            $files[] = new File('Priloha.pdf', 'application/pdf', File::ENCLOSURE, $attachment);
        }
        return new Letter('Pozvánka', $files, 'starosta');
    }

    private static function box(int $i): string
    {
        return sprintf('b%06d', $i);
    }

    /** of the account Sending::bound() sets up, as the code that queues the run computes it */
    private static function fingerprint(): string
    {
        return TestSendRun::fingerprintOf(new Account(Account::TEST, 'nszm01login', new Secret('Test-Pa55-never-shown')));
    }

    private static function items(): MemorySendItems
    {
        return TestSendRun::$items ?? throw new \LogicException('No items');
    }

    /** @return list<array<string, string>> the recipients frozen in the run's snapshot */
    private static function rows(string $id): array
    {
        $stored = self::stored($id);
        return Snapshot::read($stored['snapshot'], $stored['manifest'], false, self::CAMPAIGN_ITEM)->recipients();
    }

    private static function message(string $id, int $i): string
    {
        return self::rows($id)[$i]['msg'];
    }

    private static function state(string $id, int $i): string
    {
        return self::items()->field(self::message($id, $i), Schema::MESSAGE_STATE);
    }

    /** @return list<string> the state of each message item of the run, '' where there is none */
    private static function states(string $id): array
    {
        return array_map(static fn(array $row): string => self::items()->field($row['msg'], Schema::MESSAGE_STATE), self::rows($id));
    }

    private static function campaign(): string
    {
        return self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_STATE);
    }

    /** @return list<string> the kinds of the timeline of message $i */
    private static function kinds(string $id, int $i): array
    {
        return array_column(json_decode(self::items()->field(self::message($id, $i), Schema::MESSAGE_EVENTS), true), 'kind');
    }

    /** @return array<string, mixed> the stored position, its keys sorted */
    private static function position(string $id): array
    {
        $position = json_decode(self::stored($id)['position'], true);
        ksort($position);
        return $position;
    }

    /** @return array<int, int|string> index => what the record's map $name says of it, by index */
    private static function map(string $id, string $name): array
    {
        $map = json_decode(self::stored($id)[$name] ?? '', true) ?? [];
        ksort($map);
        return $map;
    }

    /**
     * ISDS for a step: every box active, every message accepted, numbered 1001, 1002, ... unless the
     * scripts say else; the list of sent messages only when scripted
     * @param list<Response|\Throwable|\Closure(): Response> $create
     * @param list<Response|\Throwable|\Closure(): Response> $check
     * @param list<Response|\Throwable|\Closure(): Response> $list
     */
    private static function isds(array $create = [], array $check = [], array $list = []): ScriptedIsds
    {
        $next    = 1001;
        $made    = static function () use (&$next): Response {
            return self::created((string) $next++);
        };
        $answers = ['CheckDataBox' => $check ?: [self::boxIs(1)], 'CreateMessage' => $create ?: [$made]];
        return TestSendRun::$http = new ScriptedIsds(($list === []) ? $answers : ['GetListOfSentMessages' => $list] + $answers);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/$name");
    }

    /** CheckDataBox: the box is in $state, 1 accepting messages */
    private static function boxIs(int $state): Response
    {
        return new Response(200, str_replace('<p:dbState>1<', "<p:dbState>$state<", self::fixture('check-data-box.xml')));
    }

    private static function created(string $dmId): Response
    {
        return new Response(200, str_replace('<p:dmID>1835603<', "<p:dmID>$dmId<", self::fixture('create-message-ok.xml')));
    }
}
