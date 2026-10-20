<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\AuthException;
use AA\Isds\IsdsException;
use AA\Isds\Lookup;
use AA\Isds\Outcome;
use AA\Isds\Schema;
use AA\Isds\SendItems;
use AA\Isds\SendResult;
use AA\Isds\SentMessage;
use AA\Isds\Soap;
use AA\Isds\StateChange;
use AA\Isds\StatusSync;
use AA\Isds\TransportException;
use AA_Credentials;
use AA_Field;
use AA_Metabase;
use ItemContent;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Registry\Fixtures\PropertySlice;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

// constants and data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/** The items a mass sending writes - for people to see, through the one path items are stored on */
final class SendItemsTest extends TestCase
{
    private const CAMPAIGN = 'ca000000000000000000000000000c01';
    private const MSG      = 'a0000000000000000000000000000001';
    private const SRC      = 'b0000000000000000000000000000001';
    private const AT       = 1790841600;
    // the times ISDS tells of the message sent at AT + 20: delivered, accepted by a login, after 10 days, undeliverable
    private const DELIVERED = self::AT + 3600;
    private const LOGIN     = self::AT + 7200;
    private const FICTION   = self::AT + 864000;
    private const CLOSED    = self::AT + 950400;
    /** the dmID ISDS took the message under, as the record keeps it */
    private const DM = '1835603';

    /** the run's own campaign fields as a queued campaign gets them */
    private const QUEUED = [
        Schema::CAMPAIGN_ENVIRONMENT  => 'test',
        Schema::CAMPAIGN_SENDER_BOX   => 'kv62bqf',
        Schema::CAMPAIGN_RECIPIENTS   => 175,
        Schema::CAMPAIGN_SENT_BY      => Sending::USER,
        Schema::CAMPAIGN_SENT_BY_NAME => 'Jana Nováková',
        Schema::CAMPAIGN_STARTED_AT   => self::AT,
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';             // SC_ACTIVE, the constants of config.php
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';  // the legacy boot of the real storeItem(), no DB
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                                     // AA::Pagecache()
        }
    }

    /** the process-wide state the test through the real storeItem() sets */
    protected function tearDown(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [];
        PropertySlice::forget(Sending::MESSAGE);
        (new \ReflectionProperty(AA_Credentials::class, 'instance'))->setValue(null, null);    // the slices it opened
        (new \ReflectionProperty(\AA::class, 'pagecache'))->setValue(null, null);              // opening a slice keeps the page out of it
    }

    #[DataProvider('frozen')]
    public function test_create_messageOfFrozenRecipient(string $reason, array $expected): void
    {
        $items = self::items();

        self::assertTrue($items->create(self::row($reason), 'test', 'kv62bqf', self::AT));

        self::assertSame([[
            'mode'   => 'insert_if_new',
            'flags'  => ItemContent::FLAG_JUST_STORE,
            'slice'  => Sending::MESSAGE,
            'id'     => self::MSG,
            'fields' => [
                'status_code.....'          => '1',
                Schema::MESSAGE_NAME        => 'Statutární město Brno',
                Schema::MESSAGE_BOX         => 'a7kbrrn',
                Schema::MESSAGE_ENVIRONMENT => 'test',
                Schema::MESSAGE_SENDER_BOX  => 'kv62bqf',
                Schema::MESSAGE_CAMPAIGN    => self::CAMPAIGN,
                Schema::MESSAGE_RECIPIENT   => self::SRC,
                Schema::MESSAGE_TEST        => '0',
            ] + $expected,
        ]], $items->writes);
        self::assertOnlySchema($items);
    }

    public static function frozen(): array
    {
        return [
            'to send'   => ['', [
                Schema::MESSAGE_STATE  => Schema::MESSAGE_STATE_QUEUED,
                Schema::MESSAGE_EVENTS => '[{"at":1790841600,"kind":"queued"}]',
            ]],
            'skipped'   => [Schema::BOX_DUPLICATE, [
                Schema::MESSAGE_STATE      => Schema::MESSAGE_STATE_SKIPPED,
                Schema::MESSAGE_EVENTS     => '[{"at":1790841600,"kind":"skipped","code":"duplicate"}]',
                Schema::MESSAGE_ERROR_CODE => Schema::BOX_DUPLICATE,
            ]],
        ];
    }

    // a step that died after creating it repeats the creation: the item stays as the first one wrote it
    public function test_create_secondTimeChangesNothing(): void
    {
        $items = self::items();
        $items->create(self::row(), 'test', 'kv62bqf', self::AT);
        $first = $items->stored;

        self::assertFalse($items->create(self::row(), 'production', 'other12', self::AT + 60));

        self::assertSame($first, $items->stored);
        self::assertSame(['insert_if_new', 'insert_if_new'], array_column($items->writes, 'mode'));
    }

    // a stop before prep made the item: it shows the recipient was never tried; a skipped one stays skipped
    #[DataProvider('stopped')]
    public function test_create_stoppedNeverTried(string $reason, string $state, array $entry): void
    {
        $items = self::items();

        self::assertTrue($items->create(self::row($reason), 'test', 'kv62bqf', self::AT, true));

        self::assertSame($state, $items->stored[self::MSG]['fields'][Schema::MESSAGE_STATE]);
        self::assertSame([['at' => self::AT] + $entry], self::timeline($items));
        self::assertOnlySchema($items);
    }

    public static function stopped(): array
    {
        return [
            'to send' => ['', Schema::MESSAGE_STATE_STOPPED, ['kind' => 'stopped']],
            'skipped' => [Schema::BOX_DUPLICATE, Schema::MESSAGE_STATE_SKIPPED, ['kind' => 'skipped', 'code' => 'duplicate']],
        ];
    }

    public function test_create_unknownReasonRefused(): void
    {
        $items = self::items();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->create(self::row('no box at all'), 'test', 'kv62bqf', self::AT);
        } finally {
            self::assertSame([], $items->writes);
        }
    }

    #[DataProvider('environments')]
    public function test_create_unknownEnvironmentRefused(string $environment): void
    {
        $items = self::items();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->create(self::row(), $environment, 'kv62bqf', self::AT);
        } finally {
            self::assertSame([], $items->writes);
        }
    }

    public static function environments(): array
    {
        return ['in capitals' => ['Test'], 'another word' => ['prod'], 'none' => ['']];
    }

    public function test_create_notStoredThrows(): void
    {
        $items         = self::items();
        $items->refuse = true;

        $this->expectException(\RuntimeException::class);
        $items->create(self::row(), 'test', 'kv62bqf', self::AT);
    }

    // storeItem() fails without a code: the duplicate of an earlier call must not pass for this one
    public function test_create_failureAfterDuplicateThrows(): void
    {
        $items = self::items();
        $items->create(self::row(), 'test', 'kv62bqf', self::AT);
        $items->create(self::row(), 'test', 'kv62bqf', self::AT);
        $items->silent = true;

        $this->expectException(\RuntimeException::class);
        $items->create(['msg' => 'a0000000000000000000000000000002'] + self::row(), 'test', 'kv62bqf', self::AT);
    }

    // each write adds to the timeline - the times of the fields alone miss a timeout or a lookup
    public function test_mark_appendsToTimeline(): void
    {
        $items = self::created();

        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, self::AT + 10, senderRef: '1234-1'));
        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, self::AT + 20, dmId: '1835603'));

        self::assertSame([
            ['at' => self::AT, 'kind' => 'queued'],
            ['at' => self::AT + 10, 'kind' => 'sending'],
            ['at' => self::AT + 20, 'kind' => 'submitted', 'dmID' => '1835603'],
        ], self::timeline($items));
        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(
            [Schema::MESSAGE_STATE_SENT, '1234-1', '1835603', (string) (self::AT + 20)],
            [$fields[Schema::MESSAGE_STATE], $fields[Schema::MESSAGE_SENDER_REF], $fields[Schema::MESSAGE_DM_ID], $fields[Schema::MESSAGE_SENT_AT]],
        );
        self::assertSame([['update', ItemContent::FLAG_JUST_STORE], ['update', ItemContent::FLAG_JUST_STORE]], array_map(static fn(array $w): array => [$w['mode'], $w['flags']], array_slice($items->writes, 1)));
        self::assertOnlySchema($items);
    }

    // a stop or a lookup repeated after its step died adds no second entry
    public function test_mark_onceWritesNoSecondEntry(): void
    {
        $items = self::created();

        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::AT + 10, once: true));
        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::AT + 20, once: true));

        self::assertSame([['at' => self::AT, 'kind' => 'queued'], ['at' => self::AT + 10, 'kind' => 'stopped']], self::timeline($items));
        self::assertCount(2, $items->writes, 'the create and one mark');
        self::assertFalse($items->mark('a0000000000000000000000000000009', Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::AT, once: true), 'no such item');
    }

    // sent since it was tried - ISDS took it within CreateMessage; the list tells its delivery
    public function test_markFound_sentSinceTriedWithDelivery(): void
    {
        $items = self::created();
        $sent  = new SentMessage('1835603', 4, '1234-1', 'a7kbrrn', self::AT + 300, null, 'Pozvánka');

        self::assertTrue($items->markFound(self::MSG, $sent, self::AT + 5, self::AT + 900));
        self::assertTrue($items->markFound(self::MSG, $sent, self::AT + 5, self::AT + 1800));

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(
            [Schema::MESSAGE_STATE_SENT, '1835603', (string) (self::AT + 5), (string) (self::AT + 300), '4', ''],
            [$fields[Schema::MESSAGE_STATE], $fields[Schema::MESSAGE_DM_ID], $fields[Schema::MESSAGE_SENT_AT], $fields[Schema::MESSAGE_DELIVERED_AT], $fields[Schema::MESSAGE_DM_STATUS], $fields[Schema::MESSAGE_ACCEPTED_AT] ?? ''],
        );
        self::assertSame(['at' => self::AT + 900, 'kind' => 'lookup-found', 'dmID' => '1835603'], self::timeline($items)[1]);
        self::assertCount(2, $items->writes, 'found once');
        self::assertOnlySchema($items);
    }

    // the antivirus check refused it (or took over 240 s): listed among the sent, never delivered
    public function test_markFound_refusedByAntivirusFails(): void
    {
        $items = self::created();
        $sent  = new SentMessage('1835603', SentMessage::AV_REFUSED, '1234-1', 'a7kbrrn', null, null, 'Pozvánka');

        self::assertTrue($items->markFound(self::MSG, $sent, self::AT + 5, self::AT + 900));

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(
            [Schema::MESSAGE_STATE_FAILED, '3', '1835603', '3', (string) (self::AT + 5), ''],
            [$fields[Schema::MESSAGE_STATE], $fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_DM_ID], $fields[Schema::MESSAGE_DM_STATUS], $fields[Schema::MESSAGE_SENT_AT], $fields[Schema::MESSAGE_DELIVERED_AT] ?? ''],
        );
        // ISDS tells no time of the refusal: when AA found it is no time of ISDS
        self::assertSame([
            ['at' => self::AT + 900, 'kind' => Schema::EVENT_LOOKUP_FOUND, 'dmID' => '1835603'],
            ['at' => 0, 'kind' => Schema::EVENT_AV, 'code' => '3'],
        ], array_slice(self::timeline($items), 1));
        self::assertSame(self::entry(3, 0, 0, 0), $items->markDelivery(self::MSG, self::entry(3, 0, 0, 0), '1835603'));
        self::assertCount(2, $items->writes, 'the delivery of the record shown already');
    }

    // the time ISDS took the message; a delivery or a lookup found later keeps it
    public function test_mark_sentTimeOnlyWhenSubmitted(): void
    {
        $items = self::created();
        $items->mark(self::MSG, Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, self::AT + 20, dmId: '1835603');

        $items->mark(self::MSG, Schema::MESSAGE_STATE_SENT, Schema::EVENT_DELIVERED, self::AT + 900);
        $items->mark(self::MSG, Schema::MESSAGE_STATE_SENT, Schema::EVENT_LOOKUP_FOUND, self::AT + 1800, dmId: '1835603');

        self::assertSame((string) (self::AT + 20), $items->stored[self::MSG]['fields'][Schema::MESSAGE_SENT_AT]);
        self::assertSame([1, 0, 0], array_map(static fn(array $w): int => (int) isset($w['fields'][Schema::MESSAGE_SENT_AT]), array_slice($items->writes, 1)));
    }

    /**
     * @param array<string, string> $expected the fields written besides the timeline
     * @param array<string, string> $entry    the entry added to the timeline, without its time
     */
    #[DataProvider('outcomes')]
    public function test_markOutcome_writesWhatTheAttemptMeans(SendResult|\Throwable $result, array $expected, array $entry): void
    {
        $items = self::created();

        self::assertTrue($items->markOutcome(self::MSG, Outcome::of($result), self::AT + 30));

        $written = end($items->writes)['fields'];
        self::assertSame(['at' => self::AT + 30] + $entry, array_slice(self::timeline($items), -1)[0]);
        unset($written[Schema::MESSAGE_EVENTS]);
        self::assertSame($expected, $written);
        self::assertOnlySchema($items);
    }

    public static function outcomes(): array
    {
        return [
            'sent'      => [
                new SendResult('1835603', '0000', 'Provedeno úspěšně.'),
                [Schema::MESSAGE_STATE => 'sent', Schema::MESSAGE_ERROR_CODE => '', Schema::MESSAGE_ERROR_TEXT => '', Schema::MESSAGE_DM_ID => '1835603', Schema::MESSAGE_SENT_AT => (string) (self::AT + 30)],
                ['kind' => 'submitted', 'dmID' => '1835603'],
            ],
            'refused'   => [
                new IsdsException('Odesilatel ani příjemce není OVM', '1233'),
                [Schema::MESSAGE_STATE => 'failed', Schema::MESSAGE_ERROR_CODE => '1233', Schema::MESSAGE_ERROR_TEXT => 'Odesilatel ani příjemce není OVM'],
                ['kind' => 'failed', 'code' => '1233', 'message' => 'Odesilatel ani příjemce není OVM'],
            ],
            'uncertain' => [
                TransportException::uncertain('ISDS answered HTTP 500: /srv/aa/certs/isds.key', '9001'),
                [Schema::MESSAGE_STATE => 'uncertain', Schema::MESSAGE_ERROR_CODE => '9001', Schema::MESSAGE_ERROR_TEXT => ''],
                ['kind' => 'timeout', 'code' => '9001'],
            ],
            'not sent'  => [new AuthException('ISDS refused the login'), [Schema::MESSAGE_STATE => 'queued', Schema::MESSAGE_ERROR_CODE => '', Schema::MESSAGE_ERROR_TEXT => ''], ['kind' => 'queued']],
        ];
    }

    // ISDS's words go into the item; on a cp1250 install the timeline must survive them
    public function test_mark_timelineIsAscii(): void
    {
        $items = self::created();

        $items->markOutcome(self::MSG, Outcome::of(new IsdsException('Schránka příjemce není přístupná', '1233')), self::AT);

        self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/D', $items->stored[self::MSG]['fields'][Schema::MESSAGE_EVENTS]);
        self::assertSame('Schránka příjemce není přístupná', self::timeline($items)[1]['message']);
    }

    // the error fields tell what is wrong now; what was wrong before stays in the timeline
    #[DataProvider('noProblems')]
    public function test_mark_noProblemClearsError(string $state, string $event): void
    {
        $items = self::created();
        $items->markOutcome(self::MSG, Outcome::of(TransportException::uncertain('ISDS answered HTTP 500', '9001')), self::AT + 10);
        $items->markOutcome(self::MSG, Outcome::of(new IsdsException('Odmítnuto', '1233')), self::AT + 20);

        $items->mark(self::MSG, $state, $event, self::AT + 900, code: '0000');

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(['', ''], [$fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_ERROR_TEXT]]);
        self::assertSame(['9001', '1233', '0000'], array_column(self::timeline($items), 'code'));
    }

    public static function noProblems(): array
    {
        return [
            'queued'  => [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED],
            'sending' => [Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING],
            'sent'    => [Schema::MESSAGE_STATE_SENT, Schema::EVENT_LOOKUP_FOUND],
            'stopped' => [Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED],
        ];
    }

    // a text or a path in a word's place would reopen what markOutcome() keeps out
    #[DataProvider('notWords')]
    public function test_mark_refusesTextForWord(string $dmId, string $code, string $senderRef): void
    {
        $items = self::created();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->mark(self::MSG, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::AT, $dmId, $code, $senderRef);
        } finally {
            self::assertCount(1, $items->writes);
        }
    }

    public static function notWords(): array
    {
        return [
            'a path for a code'        => ['', '/srv/aa/certs/isds.key', ''],
            'a sentence for a code'    => ['', 'Server busy', ''],
            'a code too long'          => ['', str_repeat('9', 65), ''],
            'letters for a message id' => ['18356O3', '', ''],
            'a message id too long'    => [str_repeat('1', 21), '', ''],
            'a path for a reference'   => ['', '', '/srv/aa/k.key'],
            'a reference too long'     => ['', '', str_repeat('1', 51)],
        ];
    }

    #[DataProvider('words')]
    public function test_mark_takesWords(string $dmId, string $code, string $senderRef): void
    {
        self::assertTrue(self::created()->mark(self::MSG, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::AT, $dmId, $code, $senderRef));
    }

    public static function words(): array
    {
        return [
            'a status'      => ['', '9001', ''],
            'a fault code'  => ['', 'Server.userException', ''],
            'a box problem' => ['', Schema::BOX_DUPLICATE, ''],
            'longest'       => [str_repeat('1', 20), str_repeat('9', 64), '1234-' . str_repeat('1', 45)],
            'a test ref'    => ['', '', '1234-T1'],
        ];
    }

    // the reader would drop such an entry at the next write: refused before anything is written
    public function test_markOutcome_refusesEntryTheReaderDrops(): void
    {
        $items = self::created();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->markOutcome(self::MSG, Outcome::of(new IsdsException('Odmítnuto', str_repeat('9', Soap::QUOTE + 4))), self::AT);
        } finally {
            self::assertCount(1, $items->writes);
        }
    }

    // a message item deleted by hand, or one of another slice: the run's record goes on, nothing is written
    public function test_mark_goneItemWritesNothing(): void
    {
        $items = self::items();
        $items->stored[self::MSG] = ['slice' => Sending::OTHER, 'fields' => []];

        self::assertFalse($items->mark(self::MSG, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::AT));
        self::assertFalse($items->mark(self::SRC, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::AT));

        self::assertSame([], $items->writes);
    }

    // the database keys the item by its id in small letters
    public function test_mark_findsItemByIdInCapitals(): void
    {
        $items = self::created();

        self::assertTrue($items->mark(strtoupper(self::MSG), Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, self::AT + 10));

        self::assertCount(2, self::timeline($items));
    }

    // ids as a person types them: AA keeps them in small letters
    public function test_mark_slicesInCapitals(): void
    {
        $items = self::items(strtoupper(Sending::CAMPAIGN), strtoupper(Sending::MESSAGE));
        $items->create(self::row(), 'test', 'kv62bqf', self::AT);

        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, self::AT + 10));
        self::assertTrue($items->campaign(Schema::CAMPAIGN_STATE_SENDING));

        self::assertCount(2, self::timeline($items));
    }

    #[DataProvider('outsideSchema')]
    public function test_mark_stateAndEventOnlyFromSchema(string $state, string $event, string $code = ''): void
    {
        $items = self::created();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->mark(self::MSG, $state, $event, self::AT, code: $code);
        } finally {
            self::assertCount(1, $items->writes);
        }
    }

    public static function outsideSchema(): array
    {
        return [
            'a campaign state'           => [Schema::CAMPAIGN_STATE_DONE, Schema::EVENT_DELIVERED],
            'an event'                   => [Schema::EVENT_DELIVERED, Schema::EVENT_DELIVERED],
            'a state as event'           => [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT],
            'skipped without a reason'   => [Schema::MESSAGE_STATE_SKIPPED, Schema::EVENT_SKIPPED],
            'skipped for a status'       => [Schema::MESSAGE_STATE_SKIPPED, Schema::EVENT_SKIPPED, '1233'],
        ];
    }

    public function test_mark_skippedWithReason(): void
    {
        $items = self::created();

        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_SKIPPED, Schema::EVENT_SKIPPED, self::AT, code: Schema::BOX_INACTIVE));

        $fields = $items->stored[self::MSG]['fields'];
        // a text comes only through markOutcome()
        self::assertSame([Schema::BOX_INACTIVE, ''], [$fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_ERROR_TEXT]]);
    }

    // a timeline edited by hand starts again, keeping only what this class could have written
    #[DataProvider('editedTimelines')]
    public function test_mark_editedTimelineStartsAgain(string $edited, array $kept): void
    {
        $items = self::created();
        $items->stored[self::MSG]['fields'][Schema::MESSAGE_EVENTS] = $edited;

        $items->mark(self::MSG, Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::AT);

        self::assertSame([...$kept, ['at' => self::AT, 'kind' => 'stopped']], self::timeline($items));
    }

    public static function editedTimelines(): array
    {
        $queued = ['at' => 1, 'kind' => 'queued'];
        return [
            'an object'          => ['{"edited": "by hand"}', []],
            'no JSON'            => ['odesláno ručně', []],
            // json_decode() makes INF of it, which json_encode() refuses
            'a number too large' => ['[1e400, {"at": 1, "kind": "queued"}]', [$queued]],
            'a value too large'  => ['[{"at": -1e999, "kind": "queued"}, {"at": 1, "kind": "queued"}]', [$queued]],
            'too deep'           => [str_repeat('[', 600) . str_repeat(']', 600), []],
            'a list in a list'   => ['[["queued"], {"at": 1, "kind": "queued"}]', [$queued]],
            'an empty entry'     => ['[[], {}, {"at": 1, "kind": "queued"}]', [$queued]],
            // written back, odd keys could grow it several times over
            'a key not written here' => ['[{"at": 1, "kind": "queued", "note": "by hand"}, {"at": 1, "kind": "queued"}]', [$queued]],
            // deeper than list, entry, value: no entry of this class
            'a list in an entry' => ['[{"at": 1, "kind": "queued", "x": [1]}, {"at": 2, "kind": "sending"}]', []],
            'a number inside an entry' => ['[{"at": 1, "kind": "queued", "x": [1e400]}]', []],
            // the first entry is kept for ever: a text longer than any written here goes at once
            'a text too long'    => ['[{"at": 1, "kind": "queued", "message": "' . str_repeat('x', Soap::QUOTE + 4) . '"}, {"at": 1, "kind": "queued"}]', [$queued]],
            // a letter typed in another code page costs only itself
            'invalid UTF-8'      => ["[{\"at\": 1, \"kind\": \"queued\"}, {\"at\": 2, \"kind\": \"sending\", \"message\": \"P\xF8ijato\"}]", [$queued, ['at' => 2, 'kind' => 'sending', 'message' => "P\u{FFFD}ijato"]]],
            // longer than any timeline written here: decoding it could take more memory than the step has
            'too large'          => ['[' . implode(',', array_fill(0, 40000, '{"at":1,"kind":"queued"}')) . ']', []],
        ];
    }

    public function test_mark_timelineKeepsFirstAndLast(): void
    {
        $items = self::created();
        $items->stored[self::MSG]['fields'][Schema::MESSAGE_EVENTS] = (string) json_encode(array_map(static fn(int $n): array => ['at' => $n, 'kind' => 'timeout'], range(1, 150)));

        $items->mark(self::MSG, Schema::MESSAGE_STATE_SENT, Schema::EVENT_LOOKUP_FOUND, self::AT);

        $timeline = self::timeline($items);
        self::assertCount(101, $timeline);
        self::assertSame([1, 52, self::AT], [$timeline[0]['at'], $timeline[1]['at'], $timeline[100]['at']]);
    }

    // the longest timeline written here is no edit: ISDS's longest words in 4-byte letters, 150 times
    public function test_markOutcome_longestTimelineKept(): void
    {
        $items   = self::created();
        $longest = str_repeat("\u{1F600}", Soap::QUOTE);
        $outcome = Outcome::of(new IsdsException($longest, Soap::quote($longest)));

        for ($n = 1; $n <= 150; ++$n) {
            $items->markOutcome(self::MSG, $outcome, self::AT + $n);
        }

        $timeline = self::timeline($items);
        self::assertCount(101, $timeline);
        self::assertSame([self::AT, self::AT + 51, self::AT + 150], [$timeline[0]['at'], $timeline[1]['at'], $timeline[100]['at']]);
        self::assertSame(Soap::quote($longest), $timeline[100]['message']);
    }

    // one open scope holds the read and the write, of the slice written
    public function test_mark_readsAndStoresWithSliceOpen(): void
    {
        $items = self::created();

        $items->mark(self::MSG, Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, self::AT);
        $items->campaign(Schema::CAMPAIGN_STATE_SENDING);

        self::assertSame([Sending::MESSAGE, Sending::MESSAGE, Sending::CAMPAIGN], $items->scopes);
    }

    /**
     * Through the real storeItem() into a slice behind a reading password: the timeline is read
     * unmasked, the history lists only what changed, and the slice is closed again.
     */
    #[WithoutErrorHandler]      // the full legacy storeItem() trips notices on unset keys
    public function test_mark_realStoreReadsTheSliceOpen(): void
    {
        self::realMessage();

        self::assertTrue((new SendItems(Sending::CAMPAIGN, Sending::MESSAGE, self::CAMPAIGN))->mark(self::MSG, Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, self::AT + 10, senderRef: '1234-1'));

        $timeline = array_column(array_filter(RecordingDb::$content, static fn(array $r): bool => $r['field'] === Schema::MESSAGE_EVENTS), 'text');
        self::assertSame(['[{"at":1790841600,"kind":"queued"},{"at":1790841610,"kind":"sending"}]'], array_values($timeline));
        // the old values of what changed - masked, the name would be among them
        $changed = array_column(RecordingDb::inserts('change_record'), 'value', 'selector');
        self::assertSame([Schema::MESSAGE_STATE => 'queued', Schema::MESSAGE_EVENTS => '[{"at":1790841600,"kind":"queued"}]'], $changed);
        self::assertFalse(AA_Credentials::singleton()->checkSlice(Sending::MESSAGE));
    }

    // the read keeps the reading password: only whileOpen() lets it through
    #[WithoutErrorHandler]      // the legacy GetItemContent() trips notices on unset keys
    public function test_content_keepsReadingPassword(): void
    {
        self::realMessage();
        $items = new class (Sending::CAMPAIGN, Sending::MESSAGE, self::CAMPAIGN) extends SendItems {
            /** @return array<array-key, mixed> */
            public function contentOf(string $id, string $field): array
            {
                return $this->content($id, [$field]);
            }
        };

        $content = $items->contentOf(self::MSG, Schema::MESSAGE_EVENTS);

        self::assertStringContainsString('Missing Reading Password', $content[self::MSG][Schema::MESSAGE_EVENTS][0]['value']);
    }

    public function test_markDelivery_writes_status_and_times(): void
    {
        $items = self::sent();

        self::assertSame(self::entry(4, self::DELIVERED, self::DELIVERED, 0), $items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, 0), self::DM));
        self::assertSame(self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN), $items->markDelivery(self::MSG, self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN), self::DM));

        self::assertSame([
            Schema::MESSAGE_STATE        => 'sent',
            Schema::MESSAGE_DM_ID        => self::DM,
            Schema::MESSAGE_DM_STATUS    => '6',
            Schema::MESSAGE_DELIVERED_AT => (string) self::DELIVERED,
            Schema::MESSAGE_ACCEPTED_AT  => (string) self::LOGIN,
            Schema::MESSAGE_SENT_AT      => (string) (self::AT + 20),
            Schema::MESSAGE_ERROR_CODE   => '',
            Schema::MESSAGE_ERROR_TEXT   => '',
        ], self::fields($items, [Schema::MESSAGE_STATE, Schema::MESSAGE_DM_ID, Schema::MESSAGE_DM_STATUS, Schema::MESSAGE_DELIVERED_AT, Schema::MESSAGE_ACCEPTED_AT, Schema::MESSAGE_SENT_AT, Schema::MESSAGE_ERROR_CODE, Schema::MESSAGE_ERROR_TEXT]));
        self::assertSame([['at' => self::DELIVERED, 'kind' => 'delivered'], ['at' => self::LOGIN, 'kind' => 'login']], array_slice(self::timeline($items), 2));
        self::assertSame([['update', ItemContent::FLAG_JUST_STORE], ['update', ItemContent::FLAG_JUST_STORE]], array_map(static fn(array $w): array => [$w['mode'], $w['flags']], array_slice($items->writes, 2)));
        self::assertOnlySchema($items);
    }

    // a step died between the record and the item: the record says it was sent, under which id
    #[DataProvider('behindTheRecord')]
    public function test_markDelivery_completes_item_behind_record(string $state, string $event): void
    {
        $items = self::created();
        $items->mark(self::MSG, $state, $event, self::AT + 10);

        $items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, 0), self::DM);

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(['sent', self::DM, '', ''], [$fields[Schema::MESSAGE_STATE], $fields[Schema::MESSAGE_DM_ID], $fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_SENT_AT] ?? '']);
    }

    public static function behindTheRecord(): array
    {
        return [
            'queued'    => [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED],
            'sending'   => [Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING],
            'uncertain' => [Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT],
            'stopped'   => [Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED],
        ];
    }

    // the record's ids are digits; anything else would put a text where a message id belongs
    #[DataProvider('notDmIds')]
    public function test_markDelivery_refuses_dm_id_not_digits(string $dmId): void
    {
        $items = self::sent();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, 0), $dmId);
        } finally {
            self::assertCount(2, $items->writes);
        }
    }

    public static function notDmIds(): array
    {
        return ['none' => [''], 'letters' => ['18356O3'], 'too long' => [str_repeat('1', 21)], 'a space' => ['1835603 ']];
    }

    // an entry of each kind once, however often a round tells it; submitted stays the one the sending wrote
    public function test_markDelivery_event_once_per_kind(): void
    {
        $items = self::sent();

        foreach ([
            self::entry(1, 0, 0, 0),
            self::entry(4, self::DELIVERED, self::DELIVERED, 0),
            self::entry(4, self::DELIVERED, self::DELIVERED, 0),
            self::entry(5, self::FICTION, self::DELIVERED, self::FICTION),
            self::entry(6, self::FICTION + 60, self::DELIVERED, self::FICTION),
        ] as $entry) {
            $items->markDelivery(self::MSG, $entry, self::DM);
        }

        self::assertSame(['queued', 'submitted', 'delivered', 'fiction', 'login'], array_column(self::timeline($items), 'kind'));
        self::assertSame(self::AT + 20, self::timeline($items)[1]['at']);
        self::assertSame((string) (self::AT + 20), $items->stored[self::MSG]['fields'][Schema::MESSAGE_SENT_AT], 'the time ISDS took it');
    }

    // the time of submitted is the one AA sent it at: a time the record has of status 1 moves nothing
    public function test_markDelivery_submitted_keeps_time_of_sending(): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, self::entry(1, self::AT + 50, 0, 0), self::DM);

        self::assertSame([['at' => self::AT, 'kind' => 'queued'], ['at' => self::AT + 20, 'kind' => 'submitted', 'dmID' => self::DM]], self::timeline($items));
    }

    // status 1 of a message the lookup found: the timeline gets submitted, its time AA's own SENT_AT
    public function test_markDelivery_submitted_for_1_of_found_message(): void
    {
        $items = self::created();
        $items->mark(self::MSG, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::AT + 10);
        $items->markFound(self::MSG, new SentMessage(self::DM, 1, '1234-1', 'a7kbrrn', null, null, 'Pozvánka'), self::AT + 5, self::AT + 900);

        $items->markDelivery(self::MSG, self::entry(1, 0, 0, 0), self::DM);

        self::assertSame(['queued', 'timeout', 'lookup-found', 'submitted'], array_column(self::timeline($items), 'kind'));
        self::assertSame(['at' => 0, 'kind' => 'submitted'], array_slice(self::timeline($items), -1)[0]);
        self::assertSame((string) (self::AT + 5), $items->stored[self::MSG]['fields'][Schema::MESSAGE_SENT_AT]);
    }

    // only the list of sent messages tells status 2, and it gives no time of it
    public function test_markDelivery_stamped_for_2(): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, self::entry(2, 0, 0, 0), self::DM);

        self::assertSame(['at' => 0, 'kind' => 'stamped'], array_slice(self::timeline($items), -1)[0]);
        self::assertSame('2', $items->stored[self::MSG]['fields'][Schema::MESSAGE_DM_STATUS]);
    }

    // read, deleted, in the vault: the status changes, the timeline does not
    #[DataProvider('statusOnly')]
    public function test_markDelivery_no_event_for_7_9_10(int $status): void
    {
        $accepted = self::sent();
        $accepted->markDelivery(self::MSG, self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN), self::DM);
        $timeline = self::timeline($accepted);
        $fresh    = self::sent();

        $accepted->markDelivery(self::MSG, self::entry($status, 0, self::DELIVERED, self::LOGIN), self::DM);
        $fresh->markDelivery(self::MSG, self::entry($status, 0, 0, 0), self::DM);

        self::assertSame($timeline, self::timeline($accepted));
        self::assertSame(['queued', 'submitted'], array_column(self::timeline($fresh), 'kind'));
        self::assertSame([(string) $status, (string) $status], [$accepted->stored[self::MSG]['fields'][Schema::MESSAGE_DM_STATUS], $fresh->stored[self::MSG]['fields'][Schema::MESSAGE_DM_STATUS]]);
    }

    public static function statusOnly(): array
    {
        return ['read' => [7], 'deleted' => [9], 'in the vault' => [10]];
    }

    // accepted after 10 days while the status stayed 4 (WS 1.8.3): the list of sent messages tells it so
    public function test_markDelivery_fiction_event_without_status_change(): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, self::FICTION), self::DM);

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(['4', (string) self::FICTION], [$fields[Schema::MESSAGE_DM_STATUS], $fields[Schema::MESSAGE_ACCEPTED_AT]]);
        self::assertSame([['at' => self::DELIVERED, 'kind' => 'delivered'], ['at' => self::FICTION, 'kind' => 'fiction']], array_slice(self::timeline($items), 2));
    }

    /**
     * 8 excludes a login, so its acceptance is the one after 10 days - told with 8 in one step, or by the
     * list first; a login up to a minute after the time of acceptance (6) is no acceptance after 10 days,
     * one later is
     * @param list<string> $kinds
     */
    #[DataProvider('acceptances')]
    public function test_markDelivery_fiction_only_where_no_login(array $entry, array $kinds): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, $entry, self::DM);

        self::assertSame($kinds, array_column(array_slice(self::timeline($items), 2), 'kind'));
        self::assertSame((string) $entry['a'], $items->stored[self::MSG]['fields'][Schema::MESSAGE_ACCEPTED_AT]);
    }

    public static function acceptances(): array
    {
        return [
            'undeliverable after 10 days' => [self::entry(8, self::CLOSED, self::DELIVERED, self::FICTION), ['delivered', 'fiction', 'undeliverable']],
            'a login seconds later'       => [self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN - 2), ['delivered', 'login']],
            'a login a minute later'      => [self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN - Lookup::SKEW), ['delivered', 'login']],
            'a login just over a minute'  => [self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN - Lookup::SKEW - 1), ['delivered', 'fiction', 'login']],
        ];
    }

    // the change to 4 was missed - out of the window, or a time out of range - and the list tells it later
    public function test_markDelivery_delivered_event_when_d_first_seen(): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, self::entry(5, self::FICTION, 0, self::FICTION), self::DM);
        self::assertSame([['at' => self::FICTION, 'kind' => 'fiction']], array_slice(self::timeline($items), 2));
        self::assertArrayNotHasKey(Schema::MESSAGE_DELIVERED_AT, $items->stored[self::MSG]['fields']);

        $items->markDelivery(self::MSG, self::entry(5, self::FICTION, self::DELIVERED, self::FICTION), self::DM);
        self::assertSame([['at' => self::FICTION, 'kind' => 'fiction'], ['at' => self::DELIVERED, 'kind' => 'delivered']], array_slice(self::timeline($items), 2));
        self::assertSame((string) self::DELIVERED, $items->stored[self::MSG]['fields'][Schema::MESSAGE_DELIVERED_AT]);
    }

    // a step that died after the items is repeated: nothing more is written
    public function test_markDelivery_nothing_when_shown(): void
    {
        $items = self::sent();
        $entry = self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN);
        $items->markDelivery(self::MSG, $entry, self::DM);
        $writes = count($items->writes);

        self::assertSame($entry, $items->markDelivery(self::MSG, $entry, self::DM));

        self::assertCount($writes, $items->writes);
        self::assertSame([Sending::MESSAGE, Sending::MESSAGE, Sending::MESSAGE], array_slice($items->scopes, -3), 'read with the slice open');
    }

    /** @param list<array<string, string|int>> $entries what the timeline gets */
    #[DataProvider('failedAfterSend')]
    public function test_markDelivery_av_and_undeliverable_failed_with_code(array $entry, array $entries): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, $entry, self::DM);

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(['failed', (string) $entry['s'], (string) $entry['s'], ''], [$fields[Schema::MESSAGE_STATE], $fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_DM_STATUS], $fields[Schema::MESSAGE_ERROR_TEXT]]);
        self::assertSame($entries, array_slice(self::timeline($items), 2));
        self::assertOnlySchema($items);
    }

    public static function failedAfterSend(): array
    {
        return [
            // the list of sent messages tells no time of it
            'refused by the antivirus check' => [self::entry(3, 0, 0, 0), [['at' => 0, 'kind' => 'av', 'code' => '3']]],
            'undeliverable'                  => [self::entry(8, self::CLOSED, self::DELIVERED, 0), [['at' => self::DELIVERED, 'kind' => 'delivered'], ['at' => self::CLOSED, 'kind' => 'undeliverable', 'code' => '8']]],
        ];
    }

    // the box closed after the acceptance: it is not delivered (WS 1.12.1), the time stays to be seen
    public function test_markDelivery_undeliverable_keeps_fiction_time(): void
    {
        $items = self::sent();
        $items->markDelivery(self::MSG, self::entry(5, self::FICTION, self::DELIVERED, self::FICTION), self::DM);

        $items->markDelivery(self::MSG, self::entry(8, self::CLOSED, self::DELIVERED, self::FICTION), self::DM);

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(['failed', '8', (string) self::FICTION, (string) self::DELIVERED], [$fields[Schema::MESSAGE_STATE], $fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_ACCEPTED_AT], $fields[Schema::MESSAGE_DELIVERED_AT]]);
        self::assertSame(['delivered', 'fiction', 'undeliverable'], array_column(array_slice(self::timeline($items), 2), 'kind'));
    }

    // a delivery carries no words of ISDS, and the error text of an earlier attempt goes
    public function test_markDelivery_writes_no_isds_text(): void
    {
        $items = self::created();
        $items->markOutcome(self::MSG, Outcome::of(new IsdsException('Schránka příjemce není přístupná', '1233')), self::AT + 20);

        $items->markDelivery(self::MSG, self::entry(8, self::CLOSED, self::DELIVERED, self::FICTION), self::DM);

        $fields = $items->stored[self::MSG]['fields'];
        self::assertSame(['8', ''], [$fields[Schema::MESSAGE_ERROR_CODE], $fields[Schema::MESSAGE_ERROR_TEXT]]);
        self::assertSame([], array_filter(array_slice(self::timeline($items), 2), static fn(array $e): bool => isset($e['message'])));
        self::assertSame([], array_diff_key(end($items->writes)['fields'], array_flip([Schema::MESSAGE_STATE, Schema::MESSAGE_ERROR_CODE, Schema::MESSAGE_ERROR_TEXT, Schema::MESSAGE_DM_ID, Schema::MESSAGE_DM_STATUS, Schema::MESSAGE_DELIVERED_AT, Schema::MESSAGE_ACCEPTED_AT, Schema::MESSAGE_EVENTS])));
    }

    // deleted by hand, or of another slice: the record goes on, dm_dirty drops it
    public function test_markDelivery_gone_item_null(): void
    {
        $items = self::items();
        $items->stored[self::SRC] = ['slice' => Sending::OTHER, 'fields' => []];

        self::assertNull($items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, 0), self::DM));
        self::assertNull($items->markDelivery(self::SRC, self::entry(4, self::DELIVERED, self::DELIVERED, 0), self::DM));

        self::assertSame([], $items->writes);
    }

    // what the step compares with the record after writing the item
    public function test_markDelivery_returns_shown_entry(): void
    {
        $items = self::sent();

        foreach ([self::entry(3, 0, 0, 0), self::entry(10, 0, 0, 0), self::entry(4, self::DELIVERED, self::DELIVERED, self::FICTION)] as $entry) {
            self::assertSame($entry, $items->markDelivery(self::MSG, $entry, self::DM));
            self::assertSame($entry, $items->markDelivery(self::MSG, $entry, self::DM), 'nothing written, the same entry');
        }
    }

    // the list of sent messages tells 6 without its time: 0 says so, the time of the write would pass for one of ISDS
    public function test_markDelivery_unknown_time_event_at_zero(): void
    {
        $items = self::sent();

        $items->markDelivery(self::MSG, self::entry(6, 0, self::DELIVERED, self::LOGIN), self::DM);

        self::assertSame([['at' => self::DELIVERED, 'kind' => 'delivered'], ['at' => 0, 'kind' => 'login']], array_slice(self::timeline($items), 2));
    }

    // the list tells 6 without the time of the login, the state changes of a later round with it
    public function test_markDelivery_known_time_fills_unknown_event(): void
    {
        [$listed, $changed] = self::rounds(
            [new SentMessage(self::DM, 6, '1234-1', 'a7kbrrn', self::DELIVERED, self::LOGIN, 'Pozvánka')],
            [new StateChange(self::DM, 6, self::LOGIN)],
        );
        $items = self::sent();

        $items->markDelivery(self::MSG, $listed, self::DM);
        self::assertSame(['at' => 0, 'kind' => 'login'], array_slice(self::timeline($items), -1)[0]);

        $items->markDelivery(self::MSG, $changed, self::DM);
        self::assertSame([['at' => self::DELIVERED, 'kind' => 'delivered'], ['at' => self::LOGIN, 'kind' => 'login']], array_slice(self::timeline($items), 2));
        self::assertSame((string) self::LOGIN, $items->stored[self::MSG]['fields'][Schema::MESSAGE_ACCEPTED_AT]);
    }

    /**
     * The state changes and the list date one delivery differently, the earlier told a round later: the
     * record keeps the earliest, and the field and the timeline both show it. A later time or none
     * changes the record, and so the item, no more.
     */
    public function test_markDelivery_earlier_time_moves_field_and_event(): void
    {
        $entries = self::rounds(
            [new StateChange(self::DM, 4, self::DELIVERED)],
            [new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED - 600, null, 'Pozvánka')],
            [new StateChange(self::DM, 4, self::DELIVERED + 600)],
            [new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', null, null, 'Pozvánka')],
        );
        $items = self::sent();
        $shown = [];

        foreach ($entries as $entry) {
            $items->markDelivery(self::MSG, $entry, self::DM);
            $shown[] = [$items->stored[self::MSG]['fields'][Schema::MESSAGE_DELIVERED_AT], array_column(array_slice(self::timeline($items), 2), 'at', 'kind'), count($items->writes)];
        }

        $early = self::DELIVERED - 600;
        self::assertSame([
            [(string) self::DELIVERED, ['delivered' => self::DELIVERED], 3],
            [(string) $early, ['delivered' => $early], 4],
            [(string) $early, ['delivered' => $early], 4],
            [(string) $early, ['delivered' => $early], 4],
        ], $shown);
    }

    // the list tells the acceptance of a message still 4, the state changes then 5 dated a second later: one time shows
    public function test_markDelivery_fiction_dated_as_accepted(): void
    {
        [, $changed] = self::rounds(
            [new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, self::FICTION - 1, 'Pozvánka')],
            [new StateChange(self::DM, 5, self::FICTION)],
        );
        $items = self::sent();

        $items->markDelivery(self::MSG, $changed, self::DM);

        self::assertSame(self::entry(5, self::FICTION, self::DELIVERED, self::FICTION - 1), $changed);
        self::assertSame((string) (self::FICTION - 1), $items->stored[self::MSG]['fields'][Schema::MESSAGE_ACCEPTED_AT]);
        self::assertSame(['at' => self::FICTION - 1, 'kind' => 'fiction'], array_slice(self::timeline($items), -1)[0]);
    }

    /**
     * Accepted after 10 days, then logged in: the record keeps the acceptance whichever way ISDS tells it,
     * and so does the timeline
     * @param list<list<StateChange|SentMessage>> $rounds
     * @param array<string, int>                  $timeline kind => time after the submitted entry
     */
    #[DataProvider('fictionThenLogin')]
    public function test_markDelivery_fiction_of_6_before_login(array $rounds, array $timeline): void
    {
        $entries = self::rounds(...$rounds);
        $items   = self::sent();

        foreach ($entries as $entry) {
            $items->markDelivery(self::MSG, $entry, self::DM);
        }

        self::assertSame(self::entry(6, self::FICTION + 1800, self::DELIVERED, self::FICTION), end($entries));
        self::assertSame($timeline, array_column(array_slice(self::timeline($items), 2), 'at', 'kind'));
        self::assertSame((string) self::FICTION, $items->stored[self::MSG]['fields'][Schema::MESSAGE_ACCEPTED_AT]);
    }

    public static function fictionThenLogin(): array
    {
        [$f, $l] = [self::FICTION, self::FICTION + 1800];
        return [
            // the morning reads the day of the acceptance after the round that read the login
            'the acceptance read after the login' => [
                [[new StateChange(self::DM, 4, self::DELIVERED)], [new StateChange(self::DM, 6, $l)], [new StateChange(self::DM, 5, $f)]],
                ['delivered' => self::DELIVERED, 'login' => $l, 'fiction' => $f],
            ],
            'both in one round'                   => [
                [[new StateChange(self::DM, 4, self::DELIVERED)], [new StateChange(self::DM, 5, $f), new StateChange(self::DM, 6, $l)]],
                ['delivered' => self::DELIVERED, 'fiction' => $f, 'login' => $l],
            ],
            'the list tells the acceptance'       => [
                [[new StateChange(self::DM, 4, self::DELIVERED)], [new StateChange(self::DM, 6, $l)], [new SentMessage(self::DM, 6, '1234-1', 'a7kbrrn', self::DELIVERED, $f, 'Pozvánka')]],
                ['delivered' => self::DELIVERED, 'login' => $l, 'fiction' => $f],
            ],
        ];
    }

    /**
     * Logged in, read, deleted, in the vault: the list tells an earlier time of the acceptance after 10
     * days, the timeline moves with it
     * @param list<list<StateChange|SentMessage>> $later    rounds after the acceptance
     * @param array<string, int>                  $timeline kind => time after the submitted entry
     */
    #[DataProvider('laterStatuses')]
    public function test_markDelivery_redates_fiction_in_any_status(array $later, int $s, array $timeline): void
    {
        $entries = self::rounds([new StateChange(self::DM, 4, self::DELIVERED)], [new StateChange(self::DM, 5, self::FICTION)], ...$later);
        $items   = self::sent();

        foreach ($entries as $entry) {
            $items->markDelivery(self::MSG, $entry, self::DM);
        }

        self::assertSame([$s, self::FICTION - 1], [end($entries)['s'], end($entries)['a']]);
        self::assertSame($timeline, array_column(array_slice(self::timeline($items), 2), 'at', 'kind'));
        self::assertSame((string) (self::FICTION - 1), $items->stored[self::MSG]['fields'][Schema::MESSAGE_ACCEPTED_AT]);
    }

    public static function laterStatuses(): array
    {
        $list = static fn(int $s): SentMessage => new SentMessage(self::DM, $s, '1234-1', 'a7kbrrn', self::DELIVERED, self::FICTION - 1, 'Pozvánka');
        $fiction = ['delivered' => self::DELIVERED, 'fiction' => self::FICTION - 1];
        return [
            // the list tells no time of the login
            'logged in'                 => [[[$list(6)]], 6, $fiction + ['login' => 0]],
            // too close to the login to add an acceptance after 10 days, yet the one the timeline has moves
            'logged in within a minute' => [[[new StateChange(self::DM, 6, self::FICTION + 30)], [$list(6)]], 6, $fiction + ['login' => self::FICTION + 30]],
            'read'                      => [[[$list(7)]], 7, $fiction],
            'deleted'                   => [[[$list(9)]], 9, $fiction],
            'in the vault'              => [[[$list(10)]], 10, $fiction],
        ];
    }

    /**
     * Only the rules of the status add an acceptance after 10 days: one dated by a login is none, and an
     * acceptance whose time ISDS did not tell keeps 0 - the time of a later login is not its time
     * @param list<list<StateChange|SentMessage>> $rounds
     * @param array<string, int>                  $timeline kind => time after the submitted entry
     */
    #[DataProvider('noFictionDated')]
    public function test_markDelivery_redate_adds_no_fiction(array $rounds, array $timeline): void
    {
        $items = self::sent();

        foreach (self::rounds(...$rounds) as $entry) {
            $items->markDelivery(self::MSG, $entry, self::DM);
        }

        self::assertSame($timeline, array_column(array_slice(self::timeline($items), 2), 'at', 'kind'));
    }

    public static function noFictionDated(): array
    {
        return [
            'read after a login'               => [
                [[new StateChange(self::DM, 4, self::DELIVERED)], [new StateChange(self::DM, 6, self::LOGIN)], [new SentMessage(self::DM, 7, '1234-1', 'a7kbrrn', self::DELIVERED, self::LOGIN, 'Pozvánka')]],
                ['delivered' => self::DELIVERED, 'login' => self::LOGIN],
            ],
            // a time of 5 out of range is not known
            'an acceptance of no time, a login' => [
                [[new StateChange(self::DM, 4, self::DELIVERED)], [new StateChange(self::DM, 5, self::AT - 5000)], [new StateChange(self::DM, 6, self::FICTION + 1800)]],
                ['delivered' => self::DELIVERED, 'fiction' => 0, 'login' => self::FICTION + 1800],
            ],
        ];
    }

    // the list tells the delivery of a message still 2, the state changes then 4 a few seconds later: the field and the entry show the delivery
    public function test_markDelivery_delivered_dated_by_delivery(): void
    {
        [, $delivered] = self::rounds(
            [new SentMessage(self::DM, 2, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka')],
            [new StateChange(self::DM, 4, self::DELIVERED + 5)],
        );
        $items = self::sent();

        $items->markDelivery(self::MSG, $delivered, self::DM);

        self::assertSame(self::entry(4, self::DELIVERED + 5, self::DELIVERED, 0), $delivered);
        self::assertSame([['at' => self::DELIVERED, 'kind' => 'delivered']], array_slice(self::timeline($items), 2));
        self::assertSame((string) self::DELIVERED, $items->stored[self::MSG]['fields'][Schema::MESSAGE_DELIVERED_AT]);
    }

    // only an entry StatusSync::entry() reads: anything else would write a status or a time no one told
    #[DataProvider('notEntries')]
    public function test_markDelivery_refuses_entry_not_of_record(array $entry): void
    {
        $items = self::sent();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->markDelivery(self::MSG, $entry, self::DM);
        } finally {
            self::assertCount(2, $items->writes);
        }
    }

    public static function notEntries(): array
    {
        return [
            'status 0'          => [self::entry(0, 0, 0, 0)],
            'status 11'         => [self::entry(11, 0, 0, 0)],
            'a negative time'   => [self::entry(4, -1, -1, 0)],
            'a time too large'  => [self::entry(4, 10000000000, 0, 0)],
            'a string'          => [['s' => '4', 't' => 0, 'd' => 0, 'a' => 0]],
            'a key missing'     => [['s' => 4, 't' => 0, 'd' => 0]],
            'a key more'        => [self::entry(4, 0, 0, 0) + ['x' => 0]],
            'keys out of order' => [['t' => 4, 's' => 4, 'd' => 0, 'a' => 0]],
            'none'              => [[]],
        ];
    }

    // the one way a message is written takes only entries the reader keeps: the next write would drop one, and write again
    #[DataProvider('notPerKind')]
    public function test_update_refuses_entry_per_kind_not_written_here(array $entry, bool $redate): void
    {
        $items = self::sent();
        $fields = [Schema::MESSAGE_STATE => Schema::MESSAGE_STATE_SENT];

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new \ReflectionMethod(SendItems::class, 'update'))->invoke($items, self::MSG, $fields, null, $redate ? [] : [$entry], [], $redate ? [$entry] : []);
        } finally {
            self::assertCount(2, $items->writes);
        }
    }

    public static function notPerKind(): array
    {
        $entries = [
            'a kind not of Schema' => ['at' => 0, 'kind' => 'bogus'],
            'a key not written'    => ['at' => 0, 'kind' => 'delivered', 'note' => 'x'],
            'a text too long'      => ['at' => 0, 'kind' => 'av', 'code' => str_repeat('3', Soap::QUOTE + 4)],
        ];
        $cases = [];
        foreach ($entries as $name => $entry) {
            $cases[$name] = [$entry, false];
            $cases["$name, redating"] = [$entry, true];
        }
        return $cases;
    }

    // the lookup first, the delivery then: the lookup repeated after its step died changes nothing
    public function test_markFound_then_markDelivery(): void
    {
        $items = self::uncertain();
        $items->markFound(self::MSG, new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka'), self::AT + 5, self::AT + 900);
        $items->markDelivery(self::MSG, self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN), self::DM);
        $writes = count($items->writes);

        self::assertTrue($items->markFound(self::MSG, new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka'), self::AT + 5, self::AT + 1800));

        self::assertCount($writes, $items->writes);
        self::assertSame(['6', (string) (self::AT + 5), self::DM], [$items->stored[self::MSG]['fields'][Schema::MESSAGE_DM_STATUS], $items->stored[self::MSG]['fields'][Schema::MESSAGE_SENT_AT], $items->stored[self::MSG]['fields'][Schema::MESSAGE_DM_ID]]);
        self::assertSame(['queued', 'timeout', 'lookup-found', 'delivered', 'login'], array_column(self::timeline($items), 'kind'));
    }

    // the delivery first - its step wrote the record before the lookup's items: the lookup still completes the item, once
    public function test_markDelivery_then_markFound(): void
    {
        $items = self::uncertain();
        $items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, 0), self::DM);

        self::assertTrue($items->markFound(self::MSG, new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka'), self::AT + 5, self::AT + 900));
        $writes = count($items->writes);
        self::assertTrue($items->markFound(self::MSG, new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka'), self::AT + 5, self::AT + 1800));

        self::assertCount($writes, $items->writes, 'the lookup repeated');
        self::assertSame(['sent', self::DM, (string) (self::AT + 5)], [$items->stored[self::MSG]['fields'][Schema::MESSAGE_STATE], $items->stored[self::MSG]['fields'][Schema::MESSAGE_DM_ID], $items->stored[self::MSG]['fields'][Schema::MESSAGE_SENT_AT]]);
        self::assertSame(['queued', 'timeout', 'delivered', 'lookup-found'], array_column(self::timeline($items), 'kind'));
    }

    // sent, its time shown, yet no message id: the lookup writes it
    public function test_markFound_completes_missing_dm_id(): void
    {
        $items = self::created();
        $items->mark(self::MSG, Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, self::AT + 5);

        $items->markFound(self::MSG, new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka'), self::AT + 5, self::AT + 900);

        self::assertSame(self::DM, $items->stored[self::MSG]['fields'][Schema::MESSAGE_DM_ID]);
        self::assertCount(3, $items->writes);
    }

    // an id edited into the record of another sending, or an item made by hand: no write reaches it
    #[DataProvider('notOfThisCampaign')]
    public function test_message_of_another_campaign_never_written(string $campaign): void
    {
        $items = self::created();
        $items->stored[self::MSG]['fields'][Schema::MESSAGE_CAMPAIGN] = $campaign;

        self::assertFalse($items->mark(self::MSG, Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::AT + 10));
        self::assertFalse($items->markOutcome(self::MSG, Outcome::of(new SendResult(self::DM, '0000', 'Provedeno úspěšně.')), self::AT + 20));
        self::assertFalse($items->markFound(self::MSG, new SentMessage(self::DM, 4, '1234-1', 'a7kbrrn', self::DELIVERED, null, 'Pozvánka'), self::AT + 5, self::AT + 900));
        self::assertNull($items->markDelivery(self::MSG, self::entry(4, self::DELIVERED, self::DELIVERED, 0), self::DM));

        self::assertCount(1, $items->writes, 'the create only');
    }

    public static function notOfThisCampaign(): array
    {
        return ['another campaign' => ['ca000000000000000000000000000c02'], 'none' => ['']];
    }

    // the same campaign, its id in capitals as a person may type it
    public function test_message_of_this_campaign_in_capitals_written(): void
    {
        $items = self::created();
        $items->stored[self::MSG]['fields'][Schema::MESSAGE_CAMPAIGN] = strtoupper(self::CAMPAIGN);

        self::assertTrue($items->mark(self::MSG, Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, self::AT + 10));
    }

    /**
     * Through the real storeItem() and GetItemContent(): the numbers come back from the number column as
     * they went in, so a delivery told again writes nothing and the history gets no noise
     */
    #[WithoutErrorHandler]      // the full legacy storeItem() trips notices on unset keys
    public function test_markDelivery_realStoreWritesNumbersOnce(): void
    {
        self::realMessage([Schema::MESSAGE_ERROR_CODE, Schema::MESSAGE_ERROR_TEXT, Schema::MESSAGE_DM_ID, Schema::MESSAGE_DM_STATUS, Schema::MESSAGE_DELIVERED_AT, Schema::MESSAGE_ACCEPTED_AT]);
        $items = new SendItems(Sending::CAMPAIGN, Sending::MESSAGE, self::CAMPAIGN);
        $entry = self::entry(4, self::DELIVERED, self::DELIVERED, self::FICTION);

        self::assertSame($entry, $items->markDelivery(self::MSG, $entry, self::DM));
        $statements = count(RecordingDb::$log);
        self::assertSame($entry, $items->markDelivery(self::MSG, $entry, self::DM));

        $numbers = array_column(array_filter(RecordingDb::$content, static fn(array $r): bool => in_array($r['field'], Schema::MESSAGE_NUMBER_FIELDS, true)), 'number', 'field');
        self::assertSame([Schema::MESSAGE_DM_STATUS => 4, Schema::MESSAGE_DELIVERED_AT => self::DELIVERED, Schema::MESSAGE_ACCEPTED_AT => self::FICTION], $numbers);
        self::assertSame([], array_filter(array_slice(RecordingDb::$log, $statements), static fn(string $sql): bool => !str_starts_with($sql, 'SELECT')), 'the second only reads');
    }

    public function test_campaign_onlyItsOwnFields(): void
    {
        $items = self::items();

        self::assertTrue($items->campaign(Schema::CAMPAIGN_STATE_QUEUED, self::QUEUED));

        self::assertSame([[
            'mode'   => 'update',
            'flags'  => [true, false, false, false],
            'slice'  => Sending::CAMPAIGN,
            'id'     => self::CAMPAIGN,
            'fields' => [Schema::CAMPAIGN_STATE => 'queued', Schema::CAMPAIGN_ERROR_TEXT => ''] + array_map('strval', self::QUEUED),
        ]], $items->writes);
        self::assertOnlySchema($items);
    }

    #[DataProvider('letterFields')]
    public function test_campaign_refusesFieldsNotItsOwn(string $field): void
    {
        $items = self::items();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->campaign(Schema::CAMPAIGN_STATE_SENDING, [$field => 'x']);
        } finally {
            self::assertSame([], $items->writes);
        }
    }

    public static function letterFields(): array
    {
        $fields = [...array_diff(Schema::CAMPAIGN_FIELDS, SendItems::CAMPAIGN_OWN), 'status_code.....'];
        return array_combine($fields, array_map(static fn(string $f): array => [$f], $fields));
    }

    // the code is translated when shown; an exception's text may carry the path of a key
    #[DataProvider('errors')]
    public function test_campaign_errorOnlyAsCode(string $state, string $error, bool $taken): void
    {
        $items = self::items();

        if (!$taken) {
            $this->expectException(\InvalidArgumentException::class);
        }
        try {
            $items->campaign($state, [Schema::CAMPAIGN_ERROR_TEXT => $error]);
        } finally {
            self::assertCount($taken ? 1 : 0, $items->writes);
        }

        self::assertSame($error, $items->stored[self::CAMPAIGN]['fields'][Schema::CAMPAIGN_ERROR_TEXT]);
    }

    public static function errors(): array
    {
        $curl = 'could not load PEM client certificate /srv/aa/certs/isds.key';
        return [
            'a code'                  => [Schema::CAMPAIGN_STATE_ERROR, Outcome::LOGIN, true],
            'an exception text'       => [Schema::CAMPAIGN_STATE_ERROR, $curl, false],
            'small letters'           => [Schema::CAMPAIGN_STATE_ERROR, 'isds_login', false],
            'an error without a code' => [Schema::CAMPAIGN_STATE_ERROR, '', false],
            'a code without an error' => [Schema::CAMPAIGN_STATE_SENDING, Outcome::LOGIN, false],
            'a text without an error' => [Schema::CAMPAIGN_STATE_SENDING, $curl, false],
        ];
    }

    // Continue: the first step sends again, and the error of the last one goes
    public function test_campaign_otherStateClearsError(): void
    {
        $items = self::items();
        $items->campaign(Schema::CAMPAIGN_STATE_ERROR, [Schema::CAMPAIGN_ERROR_TEXT => Outcome::CONFIG]);

        $items->campaign(Schema::CAMPAIGN_STATE_SENDING);

        self::assertSame(['sending', ''], [
            $items->stored[self::CAMPAIGN]['fields'][Schema::CAMPAIGN_STATE],
            $items->stored[self::CAMPAIGN]['fields'][Schema::CAMPAIGN_ERROR_TEXT],
        ]);
    }

    #[DataProvider('notStates')]
    public function test_campaign_stateOnlyAfterDraft(string $state): void
    {
        $items = self::items();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $items->campaign($state);
        } finally {
            self::assertSame([], $items->writes);
        }
    }

    public static function notStates(): array
    {
        return ['draft' => [Schema::CAMPAIGN_STATE_DRAFT], 'a message state' => [Schema::MESSAGE_STATE_SENT]];
    }

    // the final step repeated, or a run given up closed twice, keeps the time the sending finished
    public function test_campaign_onceKeepsStateShown(): void
    {
        $items = self::items();
        $items->campaign(Schema::CAMPAIGN_STATE_DONE, [Schema::CAMPAIGN_FINISHED_AT => self::AT]);

        self::assertTrue($items->campaign(Schema::CAMPAIGN_STATE_DONE, [Schema::CAMPAIGN_FINISHED_AT => self::AT + 60], once: true));
        self::assertTrue($items->campaign(Schema::CAMPAIGN_STATE_STOPPED, [Schema::CAMPAIGN_FINISHED_AT => self::AT + 120], once: true));

        self::assertCount(2, $items->writes);
        self::assertSame([Schema::CAMPAIGN_STATE_STOPPED, (string) (self::AT + 120)], [
            $items->stored[self::CAMPAIGN]['fields'][Schema::CAMPAIGN_STATE],
            $items->stored[self::CAMPAIGN]['fields'][Schema::CAMPAIGN_FINISHED_AT],
        ], 'another state is written');
    }

    public function test_campaign_goneWritesNothing(): void
    {
        $items = self::items();
        unset($items->stored[self::CAMPAIGN]);

        self::assertFalse($items->campaign(Schema::CAMPAIGN_STATE_DONE, [Schema::CAMPAIGN_FINISHED_AT => self::AT]));
        self::assertSame([], $items->writes);
    }

    /**
     * The message of row() in a message slice behind a reading password, read and written through the
     * real GetItemContent() and storeItem() against RecordingDb
     * @param list<string> $more further fields of the slice, those of Schema::MESSAGE_NUMBER_FIELDS in numbers
     */
    private static function realMessage(array $more = []): void
    {
        $field  = static fn(string $id, array $data = []): AA_Field => new AA_Field($data + ['id' => $id, 'slice_id' => '', 'in_item_tbl' => '', 'text_stored' => 1, 'input_show_func' => 'fld', 'input_insert_func' => 'qte', 'content_edit' => 0, 'html_default' => 0, 'multiple' => 0]);
        $fields = new FakeFields(Sending::MESSAGE, [
            $field(Schema::MESSAGE_NAME),
            $field(Schema::MESSAGE_STATE),
            $field(Schema::MESSAGE_SENDER_REF),
            $field(Schema::MESSAGE_EVENTS),
            $field('status_code.....', ['in_item_tbl' => 'status_code', 'text_stored' => 0]),
            $field('publish_date....', ['in_item_tbl' => 'publish_date', 'text_stored' => 0]),
            ...array_map(static fn(string $id): AA_Field => $field($id, ['text_stored' => (int) !in_array($id, Schema::MESSAGE_NUMBER_FIELDS, true)]), $more),
        ]);
        new PropertySlice(Sending::MESSAGE, $fields, ['historylog' => '1', 'reading_password' => AA_Credentials::encrypt('secret')]);
        $GLOBALS['spareDBs']  = [new RecordingDb()];
        $row                  = static fn(string $field, string $text): array => ['item' => self::MSG, 'field' => $field, 'number' => 0, 'text' => $text, 'flag' => 64];
        RecordingDb::$content = [$row(Schema::MESSAGE_NAME, 'Brno'), $row(Schema::MESSAGE_CAMPAIGN, self::CAMPAIGN), $row(Schema::MESSAGE_STATE, 'queued'), $row(Schema::MESSAGE_EVENTS, '[{"at":1790841600,"kind":"queued"}]')];
        RecordingDb::$answer  = static fn(string $sql): array => match (true) {
            preg_match('/^SELECT \S+ FROM item WHERE/', $sql) === 1 => [[
                'id' => pack_id(self::MSG), 'slice_id' => pack_id(Sending::MESSAGE), 'status_code' => '1', 'publish_date' => '1700000000', 'expiry_date' => '4000000000',
            ] + array_fill_keys(AA_Metabase::singleton()->getColumnNames('item'), '')],
            // a list: RecordingDb hands out rows from index 0
            str_starts_with($sql, 'SELECT * FROM content WHERE') => array_values(array_map(
                static fn(array $r): array => ['item_id' => pack_id($r['item']), 'field_id' => $r['field'], 'number' => $r['number'], 'text' => $r['text'], 'flag' => $r['flag']],
                array_filter(RecordingDb::$content, static fn(array $r): bool => (preg_match("/field_id = '([^']+)'/", $sql, $m) !== 1) || ($r['field'] === $m[1])),
            )),
            default => [],
        };
    }

    /** every field written is one of Schema, every state one of its states */
    private static function assertOnlySchema(object $items): void
    {
        foreach ($items->writes as $write) {
            $campaign = ($write['slice'] === Sending::CAMPAIGN);
            $allowed  = $campaign ? Schema::CAMPAIGN_FIELDS : [...Schema::MESSAGE_FIELDS, 'status_code.....'];
            self::assertSame([], array_diff(array_keys($write['fields']), $allowed));
            $state = $write['fields'][$campaign ? Schema::CAMPAIGN_STATE : Schema::MESSAGE_STATE];
            self::assertContains($state, $campaign ? Schema::CAMPAIGN_STATES : Schema::MESSAGE_STATES);
        }
    }

    /** @return list<array<string, string|int>> the timeline of the message of row() */
    private static function timeline(object $items): array
    {
        return json_decode($items->stored[self::MSG]['fields'][Schema::MESSAGE_EVENTS], true);
    }

    /** @return array<string, string> a frozen recipient as Snapshot::recipients() gives it */
    private static function row(string $reason = ''): array
    {
        return ['msg' => self::MSG, 'src' => self::SRC, 'box' => 'a7kbrrn', 'name' => 'Statutární město Brno', 'reason' => $reason];
    }

    /** @return array{s: int, t: int, d: int, a: int} an entry of the record's dm_state */
    private static function entry(int $s, int $t, int $d, int $a): array
    {
        return ['s' => $s, 't' => $t, 'd' => $d, 'a' => $a];
    }

    /**
     * @param list<string> $fields
     * @return array<string, string> $fields of the message of row() as stored, '' for one it has not
     */
    private static function fields(object $items, array $fields): array
    {
        return array_map(static fn(string $f): string => $items->stored[self::MSG]['fields'][$f] ?? '', array_combine($fields, $fields));
    }

    /**
     * The record's entry of the message after each round, as following merges what ISDS told
     * @param list<StateChange|SentMessage> ...$rounds
     * @return list<array{s: int, t: int, d: int, a: int}>
     */
    private static function rounds(array ...$rounds): array
    {
        [$state, $entries] = [[], []];
        foreach ($rounds as $events) {
            $state     = StatusSync::merge($state, $events, StatusSync::indexOf([self::DM]), self::AT, self::AT + 30 * 86400)['state'];
            $entries[] = StatusSync::entry($state[0]);
        }
        return $entries;
    }

    /** the items with the message of row() of unknown fate: its sending got no answer */
    private static function uncertain(): object
    {
        $items = self::created();
        $items->mark(self::MSG, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::AT + 10);
        return $items;
    }

    /** the items with the message of row() sent: ISDS took it at AT + 20 */
    private static function sent(): object
    {
        $items = self::created();
        $items->markOutcome(self::MSG, Outcome::of(new SendResult(self::DM, '0000', 'Provedeno úspěšně.')), self::AT + 20);
        return $items;
    }

    /** the items with the message of row() created */
    private static function created(): object
    {
        $items = self::items();
        $items->create(self::row(), 'test', 'kv62bqf', self::AT);
        return $items;
    }

    /** SendItems over items in memory: what it would store, and what a store keeps of it */
    private static function items(string $campaignSlice = Sending::CAMPAIGN, string $messageSlice = Sending::MESSAGE): object
    {
        $items = new class ($campaignSlice, $messageSlice, self::CAMPAIGN) extends SendItems {
            /** @var array<string, array{slice: string, fields: array<string, string>}> item id in small letters => what a store keeps */
            public array $stored = [];
            /** @var list<array{mode: string, flags: list<bool>, slice: string, id: string, fields: array<string, string>}> */
            public array $writes = [];
            /** @var list<string> the slice of each whileOpen() */
            public array $scopes = [];
            /** storeItem() fails and says why */
            public bool $refuse = false;
            /** storeItem() fails and says nothing */
            public bool $silent = false;
            private string $open = '';

            protected function store(ItemContent $content, string $mode, array $flags): bool
            {
                Assert::assertSame(strtolower($this->open), $content->getSliceID(), 'stored outside its open slice');
                $fields = [];
                foreach ($content->getContent() as $field => $values) {
                    if (!in_array($field, ['id..............', 'unpacked_id.....', 'slice_id........'], true)) {
                        $fields[$field] = (string) $values[0]['value'];
                    }
                }
                $id             = strtolower($content->getItemID());
                $this->writes[] = ['mode' => $mode, 'flags' => $flags, 'slice' => $content->getSliceID(), 'id' => $id, 'fields' => $fields];
                if ($this->silent) {
                    return false;
                }
                if ($this->refuse || (($mode === 'insert_if_new') && isset($this->stored[$id]))) {
                    ItemContent::lastErr($this->refuse ? \ITEMCONTENT_ERROR_INVALID : \ITEMCONTENT_ERROR_DUPLICATE, 'not stored');
                    return false;
                }
                $this->stored[$id] = ['slice' => $content->getSliceID(), 'fields' => $fields + ($this->stored[$id]['fields'] ?? [])];
                return true;
            }

            // as GetItemContent(): keyed by the id in small letters, the slice of every item
            protected function content(string $id, array $fields): array
            {
                Assert::assertNotSame('', $this->open, 'read outside an open slice');
                $id   = strtolower($id);
                $item = $this->stored[$id] ?? null;
                if (is_null($item)) {
                    return [];
                }
                $values = array_map(static fn(string $v): array => [['value' => $v]], array_intersect_key($item['fields'], array_flip($fields)));
                return [$id => ['u_slice_id......' => [['value' => $item['slice']]]] + $values];
            }

            protected function whileOpen(string $slice, \Closure $fn): bool
            {
                $this->scopes[] = $slice;
                $this->open     = $slice;
                try {
                    return $fn();
                } finally {
                    $this->open = '';
                }
            }
        };
        $items->stored[self::CAMPAIGN] = ['slice' => Sending::CAMPAIGN, 'fields' => [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_DRAFT]];
        return $items;
    }
}
