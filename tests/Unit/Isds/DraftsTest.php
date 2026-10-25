<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Actions;
use AA\Isds\Drafts;
use AA\Isds\RecipientFilter;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\MemoryDrafts;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/fixtures/Sending.php';
require_once __DIR__ . '/fixtures/MemoryDrafts.php';

/**
 * Drafts: a page saves the letter of a draft and whom it goes to - only while it is a draft of the
 * page, never its state, a field of a sending or another slice; a new one once for the form shown
 */
final class DraftsTest extends TestCase
{
    private const NONCE = '0123456789abcdef0123456789abcdef';
    private const ITEM  = 'ca000000000000000000000000000c01';
    // an item of the source slice
    private const ADAMOV = 'a0000000000000000000000000000001';

    /** the letter fields a save may write; a new draft adds its bin, state and author */
    private const LETTER = [Schema::CAMPAIGN_SUBJECT, Schema::CAMPAIGN_TO_HANDS, Schema::CAMPAIGN_BODY, Schema::CAMPAIGN_FILTER];

    private MemoryDrafts $drafts;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
    }

    protected function setUp(): void
    {
        $this->drafts = new MemoryDrafts();
        session_id('drafts-test-session');
    }

    protected function tearDown(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array{code: string, campaign: string, field: string}
     */
    private function save(array $post, string $campaign = '', ?Setup $page = null): array
    {
        return $this->drafts->save($page ?? Sending::page(), $campaign, $post);
    }

    /** @param array<string, mixed> $post the letter of a new draft, the form of NONCE */
    private function saveNew(array $post = []): array
    {
        return $this->save($post + ['isds_nonce' => self::NONCE]);
    }

    private static function newId(): string
    {
        return Drafts::newId(Sending::page(), self::NONCE);
    }

    /** @param array{code: string, campaign: string, field: string} $result */
    private function assertRefusedNothingWritten(string $code, string $field, array $result): void
    {
        self::assertSame([$code, $field], [$result['code'], $result['field']]);
        self::assertSame([], $this->drafts->writes, 'nothing written');
    }

    /** @return list<string> the fields of the last write */
    private function lastWritten(): array
    {
        return array_keys(end($this->drafts->writes)['fields']);
    }

    // ------------------------------------------------------------ a new draft

    public function test_save_newDraftOnce(): void
    {
        $result = $this->saveNew(['isds_subject' => ' Výzva ', 'isds_body' => '<p>Dobrý den</p>']);

        $id = self::newId();
        self::assertSame(['code' => Drafts::SAVED, 'campaign' => $id, 'field' => ''], $result);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertSame([['insert_if_new', $id, Sending::CAMPAIGN, true]], array_map(static fn(array $w): array => [$w['mode'], $w['id'], $w['slice'], $w['open']], $this->drafts->writes));
        self::assertSame(['1', 'draft', 'Výzva', '<p>Dobrý den</p>'], [
            $this->drafts->field($id, 'status_code.....'),
            $this->drafts->field($id, Schema::CAMPAIGN_STATE),
            $this->drafts->field($id, Schema::CAMPAIGN_SUBJECT),
            $this->drafts->field($id, Schema::CAMPAIGN_BODY),
        ]);
        self::assertSame(['lock ' . SendRun::lockName($id), 'unlock ' . SendRun::lockName($id)], $this->drafts->lockLog);
    }

    // autosaves and a double click of the form shown: one item, each save into it
    public function test_save_sameNonceUpdatesTheSameItem(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $again = $this->saveNew(['isds_subject' => 'Výzva 2']);

        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::newId(), 'field' => ''], $again);
        self::assertCount(1, $this->drafts->items);
        self::assertSame(['insert_if_new', 'insert_if_new', 'update'], array_column($this->drafts->writes, 'mode'));
        self::assertSame([Schema::CAMPAIGN_SUBJECT], $this->lastWritten());
        self::assertSame('Výzva 2', $this->drafts->field(self::newId(), Schema::CAMPAIGN_SUBJECT));
    }

    public function test_save_otherSessionOtherItem(): void
    {
        $this->saveNew();
        session_id('another-session');
        $other = $this->saveNew();

        self::assertNotSame(self::newId(), $this->drafts->writes[0]['id']);
        self::assertSame($other['campaign'], self::newId());
        self::assertCount(2, $this->drafts->items);
    }

    // the id would be known to anyone: the key of the form is the session
    public function test_save_noSessionRefused(): void
    {
        session_id('');

        $this->assertRefusedNothingWritten(Actions::EXPIRED, '', $this->saveNew(['isds_subject' => 'Výzva']));
        self::assertSame([], $this->drafts->lockLog);
    }

    // the id of a draft is never a token of a form, which the same key signs
    public function test_save_newIdIsNoToken(): void
    {
        self::assertStringStartsNotWith(self::newId(), \AA\Util\FormToken::get('isds:save:' . Sending::page()->bindingKey() . ':'));
        self::assertStringStartsNotWith(self::newId(), \AA\Util\FormToken::get(self::NONCE));
    }

    /** @return array<string, array{mixed}> */
    public static function badNonces(): array
    {
        return [
            'none'        => [null],
            'short'       => [substr(self::NONCE, 1)],
            'capitals'    => [strtoupper(self::NONCE)],
            'a line end'  => [self::NONCE . "\n"],
            'not hex'     => [str_repeat('g', 32)],
            'an array'    => [[self::NONCE]],
        ];
    }

    #[DataProvider('badNonces')]
    public function test_save_badNonceIsInput(mixed $nonce): void
    {
        $post = ['isds_subject' => 'Výzva'] + (is_null($nonce) ? [] : ['isds_nonce' => $nonce]);

        $result = $this->save($post);

        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::NONCE, $result);
        self::assertSame('', $result['campaign']);
    }

    // the form of a new draft shown before the draft was queued: the sending is a record now
    public function test_save_staleNonceAfterQueueNotDraft(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $this->drafts->items[self::newId()]['fields'][Schema::CAMPAIGN_STATE] = ['queued', 0];

        $result = $this->saveNew(['isds_subject' => 'Změněno']);

        self::assertSame([SendStart::NOT_DRAFT, self::newId()], [$result['code'], $result['campaign']]);
        self::assertSame(['insert_if_new', 'insert_if_new'], array_column($this->drafts->writes, 'mode'), 'the second refused as a duplicate, no update');
        self::assertSame('Výzva', $this->drafts->field(self::newId(), Schema::CAMPAIGN_SUBJECT));
    }

    /** @return array<string, array{bool}> */
    public static function withRun(): array
    {
        return ['never queued' => [false], 'queued once' => [true]];
    }

    #[DataProvider('withRun')]
    public function test_save_staleNonceAfterTrashNotRevived(bool $queued): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $this->drafts->items[self::newId()]['fields']['status_code.....'] = ['3', 0];
        $this->drafts->runs = $queued ? [self::newId()] : [];

        $result = $this->saveNew(['isds_subject' => 'Změněno']);

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'field' => ''], $result);
        self::assertNotContains('update', array_column($this->drafts->writes, 'mode'));
        self::assertSame('3', $this->drafts->field(self::newId(), 'status_code.....'));
    }

    // storeItem() would move it into the campaign slice
    public function test_save_duplicateIdOfOtherSliceUnknown(): void
    {
        $this->drafts->draft(self::newId(), Sending::OTHER);

        $result = $this->saveNew(['isds_subject' => 'Výzva']);

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'field' => ''], $result);
        self::assertSame(['insert_if_new'], array_column($this->drafts->writes, 'mode'));
        self::assertSame(Sending::OTHER, $this->drafts->items[self::newId()]['slice']);
    }

    // storeItem() writes the login of the request as the author; the form names none
    public function test_save_postedByIsTheLogin(): void
    {
        $this->saveNew(['posted_by.......' => '666', 'isds_posted_by' => '666']);

        self::assertSame(['status_code.....', Schema::CAMPAIGN_STATE], $this->lastWritten());
    }

    // feeding would spread an unfinished letter to other slices, events would run planned tasks of a draft
    public function test_save_flagsNoFeedingNoEvents(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $this->saveNew(['isds_subject' => 'Výzva 2']);

        self::assertSame([[true, false, false, false]], array_values(array_unique(array_column($this->drafts->writes, 'flags'), SORT_REGULAR)));
        self::assertCount(3, $this->drafts->writes);
    }

    // a packed id loses a trailing zero byte (DbBinaryTrailingZeros), and '27' and a trailing '20' are no safe bytes either
    public function test_newId_asAaMakesIds(): void
    {
        for ($i = 0; $i < 300; ++$i) {
            $id = Drafts::newId(Sending::page(), md5("nonce $i"));
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
            self::assertStringNotContainsString('00', $id);
            self::assertStringNotContainsString('27', $id);
            self::assertStringEndsNotWith('20', $id);
        }
    }

    // an autosave made the draft, then Save of a field it cannot take: the page goes on with that draft,
    // and the next save writes into it, never a second one
    public function test_save_inputKeepsTheNewDraft(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);

        $refused = $this->saveNew(['isds_to_hands' => str_repeat('a', 31)]);
        $next    = $this->save(['isds_to_hands' => 'starosta'], $refused['campaign']);

        self::assertSame(['code' => Actions::INPUT, 'campaign' => self::newId(), 'field' => Drafts::TO_HANDS], $refused);
        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::newId(), 'field' => ''], $next);
        self::assertSame([self::newId()], array_keys($this->drafts->items));
    }

    // queued, then deleted for good: a stale form of it does not make the draft again under its run
    public function test_save_staleNonceAfterDeletionNotMadeAgain(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $this->drafts->runs = [self::newId()];
        $this->drafts->items = [];

        self::assertSame(['code' => SendStart::ALREADY, 'campaign' => self::newId(), 'field' => ''], $this->saveNew(['isds_subject' => 'Výzva 2']));
        self::assertSame([[], 1], [$this->drafts->items, count($this->drafts->writes)]);
    }

    // ------------------------------------------------------------ a draft of the page

    public function test_save_updateNeverWritesStatusCode(): void
    {
        $this->drafts->draft(self::ITEM);

        $result = $this->save(['isds_subject' => 'Výzva', 'isds_body' => '<p>x</p>'], self::ITEM);

        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::ITEM, 'field' => ''], $result);
        self::assertSame(['update'], array_column($this->drafts->writes, 'mode'));
        self::assertSame([Schema::CAMPAIGN_SUBJECT, Schema::CAMPAIGN_BODY], $this->lastWritten());
    }

    // the state, the fields of a sending and those AA keeps are no fields of the form
    public function test_save_onlyLetterFieldsWritten(): void
    {
        $this->drafts->draft(self::ITEM);
        $post = [
            'isds_subject'    => 'Výzva',
            'isds_to_hands'   => 'starosta',
            'isds_body'       => '<p>x</p>',
            'isds_membership' => 'all',
            'isds_types'      => ['mesto'],
            'isds_state'      => 'queued',
            'isds_sent_by'    => '1',
            Schema::CAMPAIGN_STATE     => 'queued',
            Schema::CAMPAIGN_SENT_BY   => '1',
            'status_code.....'         => '1',
            'aa'              => ['x' => ['text...........3' => 'queued']],
        ];

        self::assertSame(Drafts::SAVED, $this->save($post, self::ITEM)['code']);
        self::assertSame([], array_diff($this->lastWritten(), self::LETTER));
        self::assertSame('draft', $this->drafts->field(self::ITEM, Schema::CAMPAIGN_STATE));
    }

    public function test_save_missingFieldKept(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->save(['isds_subject' => 'Výzva', 'isds_body' => '<p>Text</p>', 'isds_to_hands' => 'starosta'], self::ITEM);

        $this->save(['isds_subject' => 'Jiná výzva'], self::ITEM);

        self::assertSame([Schema::CAMPAIGN_SUBJECT], $this->lastWritten());
        self::assertSame(['<p>Text</p>', 'starosta'], [$this->drafts->field(self::ITEM, Schema::CAMPAIGN_BODY), $this->drafts->field(self::ITEM, Schema::CAMPAIGN_TO_HANDS)]);
    }

    // a form without the choice of recipients - one of the letter only - keeps the filter saved
    public function test_save_filterWrittenOnlyWithMembership(): void
    {
        $this->drafts->draft(self::ITEM);

        $this->save(['isds_subject' => 'Výzva', 'isds_types' => ['mestocast'], 'isds_include' => self::ADAMOV], self::ITEM);
        self::assertSame('', $this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER));

        $this->save(['isds_membership' => 'members', 'isds_types' => ['mestocast', 'mesto'], 'isds_include' => strtoupper(self::ADAMOV) . '-' . self::ADAMOV, 'isds_exclude' => ''], self::ITEM);
        self::assertSame(
            ['types' => ['mestocast', 'mesto'], 'membership' => 'members', 'include' => [self::ADAMOV], 'exclude' => []],
            json_decode($this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER), true),
        );
    }

    // a copy of a large sending for the unsent: one field, never cut by max_input_vars
    public function test_save_includeOfMaxRecipients(): void
    {
        $this->drafts->draft(self::ITEM);
        $ids = array_map(static fn(int $i): string => sprintf('b%031x', $i), range(1, Setup::MAX_RECIPIENTS));

        $result = $this->save(['isds_membership' => 'all', 'isds_include' => implode('-', $ids)], self::ITEM);

        self::assertSame(Drafts::SAVED, $result['code']);
        self::assertSame($ids, json_decode($this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER), true)['include']);

        $this->drafts->writes = [];
        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::TOO_MANY, $this->save(['isds_membership' => 'all', 'isds_exclude' => implode('-', [...$ids, 'c' . str_repeat('0', 31)])], self::ITEM));
    }

    // a field of megabytes is not split into a list first
    public function test_save_idsLongerThanAFilterRefused(): void
    {
        $this->drafts->draft(self::ITEM);

        $result = $this->save(['isds_membership' => 'all', 'isds_include' => str_repeat('a', RecipientFilter::MAX_BYTES + 1)], self::ITEM);

        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::TOO_MANY, $result);
    }

    /** @return array<string, array{array<string, mixed>, ?Setup}> */
    public static function notOffered(): array
    {
        $no = static fn(array $over): array => [$over + ['isds_membership' => 'all'], null];

        return [
            'a type not offered'          => $no(['isds_types' => ['kraj']]),
            'types as a text'             => $no(['isds_types' => 'mesto']),
            'types not a list of texts'   => $no(['isds_types' => [['mesto']]]),
            'a membership not offered'    => $no(['isds_membership' => 'some']),
            'a membership as an array'    => $no(['isds_membership' => ['all']]),
            'members of no member field'  => [['isds_membership' => 'members'], Sending::page('')],
            'an id that is none'          => $no(['isds_include' => self::ADAMOV . '-x']),
            'ids as an array'             => $no(['isds_exclude' => [self::ADAMOV]]),
            'an empty id between dashes'  => $no(['isds_include' => self::ADAMOV . '--' . self::ADAMOV]),
            'a type in another encoding'  => $no(['isds_types' => ["m\xE9sto"]]),
        ];
    }

    #[DataProvider('notOffered')]
    public function test_save_filterNotOfferedRefused(array $post, ?Setup $page): void
    {
        $this->drafts->draft(self::ITEM);

        $result = $this->save(['isds_subject' => 'Výzva'] + $post, self::ITEM, $page);

        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::RECIPIENTS, $result);
        self::assertSame(self::ITEM, $result['campaign']);
    }

    /** @return array<string, array{\Closure(): array<string, mixed>, string}> the form made in the test: PHPUnit keeps and exports what a provider gives */
    public static function tooLong(): array
    {
        return [
            'subject'      => [static fn(): array => ['isds_subject' => str_repeat('a', 256)], Drafts::SUBJECT],
            'to hands'     => [static fn(): array => ['isds_to_hands' => str_repeat('č', 31)], Drafts::TO_HANDS],
            'body'         => [static fn(): array => ['isds_body' => str_repeat('a', Drafts::MAX_BODY + 1)], Drafts::BODY],
            'subject list' => [static fn(): array => ['isds_subject' => ['Výzva']], Drafts::SUBJECT],
            'body list'    => [static fn(): array => ['isds_body' => ['x']], Drafts::BODY],
        ];
    }

    // the other fields valid: nothing of the form is saved
    #[DataProvider('tooLong')]
    public function test_save_tooLongRefusedNothingWritten(\Closure $post, string $field): void
    {
        $this->drafts->draft(self::ITEM);

        $this->assertRefusedNothingWritten(Actions::INPUT, $field, $this->save($post() + ['isds_subject' => 'Výzva', 'isds_body' => '<p>x</p>', 'isds_to_hands' => 'starosta'], self::ITEM));
        self::assertSame([], $this->drafts->lockLog, 'refused before the lock');
    }

    public function test_save_longestTaken(): void
    {
        $this->drafts->draft(self::ITEM);
        $post = ['isds_subject' => str_repeat('č', 255), 'isds_to_hands' => str_repeat('č', 30), 'isds_body' => str_repeat('a', Drafts::MAX_BODY)];

        self::assertSame(Drafts::SAVED, $this->save($post, self::ITEM)['code']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function notText(): array
    {
        return [
            'subject cut in a letter' => [['isds_subject' => "V\xC3"], Drafts::SUBJECT],
            'a tab in the subject'    => [['isds_subject' => "Vý\tzva"], Drafts::SUBJECT],
            'cp1250 to hands'         => [['isds_to_hands' => "starosta \x9Aance"], Drafts::TO_HANDS],
            'body cut in a letter'    => [['isds_body' => "<p>\xC3\x28</p>"], Drafts::BODY],
        ];
    }

    #[DataProvider('notText')]
    public function test_save_invalidUtf8Refused(array $post, string $field): void
    {
        $this->drafts->draft(self::ITEM);

        $this->assertRefusedNothingWritten(Actions::INPUT, $field, $this->save($post, self::ITEM));
    }

    // HtmlBody cleans it when the letter is made; a body stored as plain text would go as plain text
    public function test_save_bodyStoredAsHtml(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_BODY] = ['<p>x</p>', 0];

        $this->save(['isds_body' => '<p>x</p>'], self::ITEM);

        self::assertSame(['<p>x</p>', FLAG_HTML], $this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_BODY]);
        self::assertSame([Schema::CAMPAIGN_BODY], $this->lastWritten());
    }

    /** @return array<string, array{string}> */
    public static function notDrafts(): array
    {
        return array_combine(['queued', 'sending', 'done', 'stopped', 'error', ''], array_map(static fn(string $s): array => [$s], ['queued', 'sending', 'done', 'stopped', 'error', '']));
    }

    #[DataProvider('notDrafts')]
    public function test_save_notDraftRefused(string $state): void
    {
        $this->drafts->draft(self::ITEM, state: $state);

        self::assertSame(['code' => SendStart::NOT_DRAFT, 'campaign' => self::ITEM, 'field' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
        self::assertSame([], $this->drafts->writes);
    }

    // the item edited back to draft: its sending is a record still
    public function test_save_draftWithRunRefused(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->runs = [self::ITEM];

        self::assertSame(['code' => SendStart::ALREADY, 'campaign' => self::ITEM, 'field' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
        self::assertSame([], $this->drafts->writes);
    }

    /** @return array<string, array{int, string}> */
    public static function notOfThePage(): array
    {
        return ['in the trash' => [3, Sending::CAMPAIGN], 'in the holding bin' => [2, Sending::CAMPAIGN], 'of another slice' => [1, Sending::OTHER]];
    }

    #[DataProvider('notOfThePage')]
    public function test_save_campaignNotOfThePageUnknown(int $status, string $slice): void
    {
        $this->drafts->draft(self::ITEM, $slice, $status);

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'field' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
        self::assertSame([], $this->drafts->writes);
    }

    // queueing holds the lock of the campaign while it freezes the letter
    public function test_save_busyWhileQueued(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->lockedByOther = true;

        self::assertSame(['code' => SendStart::BUSY, 'campaign' => self::ITEM, 'field' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
        self::assertSame([], $this->drafts->writes);
        self::assertSame(['lock ' . SendRun::lockName(self::ITEM)], $this->drafts->lockLog);
    }

    // an autosave of a form nobody touched: the history of the slice gets no row
    public function test_save_unchangedWritesNothing(): void
    {
        $this->drafts->draft(self::ITEM);
        $post = ['isds_subject' => 'Výzva', 'isds_to_hands' => '', 'isds_body' => '<p>x</p>', 'isds_membership' => 'all', 'isds_types' => ['mesto']];
        $this->save($post, self::ITEM);
        $this->drafts->writes = [];

        self::assertSame(Drafts::SAVED, $this->save($post, self::ITEM)['code']);
        self::assertSame([], $this->drafts->writes);
    }

    public function test_save_writesWithTheSliceOpenUnderTheLock(): void
    {
        $this->drafts->draft(self::ITEM);

        $this->save(['isds_subject' => 'Výzva'], self::ITEM);

        self::assertTrue($this->drafts->writes[0]['open']);
        self::assertSame(['lock ' . SendRun::lockName(self::ITEM), 'unlock ' . SendRun::lockName(self::ITEM)], $this->drafts->lockLog);
    }
}
