<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\AuthException;
use AA\Isds\IsdsException;
use AA\Isds\Outcome;
use AA\Isds\Schema;
use AA\Isds\SendItems;
use AA\Isds\SendResult;
use AA\Isds\SentMessage;
use AA\Isds\Soap;
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
                Schema::MESSAGE_OFFICIAL    => 'Statutární město Brno',
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
            'skipped'   => [Schema::BOX_FOREIGN, [
                Schema::MESSAGE_STATE      => Schema::MESSAGE_STATE_SKIPPED,
                Schema::MESSAGE_EVENTS     => '[{"at":1790841600,"kind":"skipped","code":"foreign"}]',
                Schema::MESSAGE_ERROR_CODE => Schema::BOX_FOREIGN,
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
            'skipped' => [Schema::BOX_KIND, Schema::MESSAGE_STATE_SKIPPED, ['kind' => 'skipped', 'code' => 'kind']],
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
        self::assertSame(['at' => self::AT + 900, 'kind' => Schema::EVENT_AV, 'dmID' => '1835603', 'code' => '3'], self::timeline($items)[1]);
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
                return $this->content($id, $field);
            }
        };

        $content = $items->contentOf(self::MSG, Schema::MESSAGE_EVENTS);

        self::assertStringContainsString('Missing Reading Password', $content[self::MSG][Schema::MESSAGE_EVENTS][0]['value']);
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
     */
    private static function realMessage(): void
    {
        $field  = static fn(string $id, array $data = []): AA_Field => new AA_Field($data + ['id' => $id, 'slice_id' => '', 'in_item_tbl' => '', 'text_stored' => 1, 'input_show_func' => 'fld', 'input_insert_func' => 'qte', 'content_edit' => 0, 'html_default' => 0, 'multiple' => 0]);
        $fields = new FakeFields(Sending::MESSAGE, [
            $field(Schema::MESSAGE_NAME),
            $field(Schema::MESSAGE_STATE),
            $field(Schema::MESSAGE_SENDER_REF),
            $field(Schema::MESSAGE_EVENTS),
            $field('status_code.....', ['in_item_tbl' => 'status_code', 'text_stored' => 0]),
            $field('publish_date....', ['in_item_tbl' => 'publish_date', 'text_stored' => 0]),
        ]);
        new PropertySlice(Sending::MESSAGE, $fields, ['historylog' => '1', 'reading_password' => AA_Credentials::encrypt('secret')]);
        $GLOBALS['spareDBs']  = [new RecordingDb()];
        $row                  = static fn(string $field, string $text): array => ['item' => self::MSG, 'field' => $field, 'number' => 0, 'text' => $text, 'flag' => 64];
        RecordingDb::$content = [$row(Schema::MESSAGE_NAME, 'Brno'), $row(Schema::MESSAGE_STATE, 'queued'), $row(Schema::MESSAGE_EVENTS, '[{"at":1790841600,"kind":"queued"}]')];
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
        return ['msg' => self::MSG, 'src' => self::SRC, 'box' => 'a7kbrrn', 'name' => 'Statutární město Brno', 'official' => 'Statutární město Brno', 'reason' => $reason];
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
            protected function content(string $id, string $field): array
            {
                Assert::assertNotSame('', $this->open, 'read outside an open slice');
                $id   = strtolower($id);
                $item = $this->stored[$id] ?? null;
                if (is_null($item)) {
                    return [];
                }
                return [$id => ['u_slice_id......' => [['value' => $item['slice']]]] + (isset($item['fields'][$field]) ? [$field => [['value' => $item['fields'][$field]]]] : [])];
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
