<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Actions;
use AA\Isds\Drafts;
use AA\Isds\Message;
use AA\Isds\RecipientFilter;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\MemoryDrafts;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/fixtures/Sending.php';
require_once __DIR__ . '/fixtures/MemoryDrafts.php';
require_once __DIR__ . '/fixtures/FakeRecipients.php';

/**
 * Drafts: a page saves the letter of a draft and whom it goes to - only while it is a draft of the
 * page, never its state, a field of a sending or another slice; a new one once for the form shown
 */
final class DraftsTest extends TestCase
{
    private const NONCE = '0123456789abcdef0123456789abcdef';
    private const ITEM  = 'ca000000000000000000000000000c01';
    // items of the source slice, one in the trash; an item of another slice
    private const ADAMOV           = 'a0000000000000000000000000000001';
    private const BENESOV          = 'b0000000000000000000000000000002';
    private const CHEB             = 'c0000000000000000000000000000003';
    private const TRASHED          = 'd0000000000000000000000000000004';
    private const OTHER_SLICE_ITEM = 'e0000000000000000000000000000005';

    /** the letter fields a save may write; a new draft adds its bin, state and author */
    private const LETTER = [Schema::CAMPAIGN_SUBJECT, Schema::CAMPAIGN_TO_HANDS, Schema::CAMPAIGN_BODY, Schema::CAMPAIGN_FILTER];

    private MemoryDrafts $drafts;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
        if (!\defined('DEFAULT_LANG_INCLUDE')) {
            \define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');
        }
        require_once __DIR__ . '/../../../include/files.class.php';   // Files::escape()
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
     * The form as the page shows it: with the filter it read - the one stored now -, unless $post tells another
     * @param array<string, mixed> $post
     * @return array{code: string, campaign: string, field: string, file: string}
     */
    private function save(array $post, string $campaign = '', ?Setup $page = null): array
    {
        $post += ['isds_filter_seen' => Drafts::filterSeen($this->drafts->field(($campaign === '') ? self::newId() : $campaign, Schema::CAMPAIGN_FILTER))];

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

    /** @param array{code: string, campaign: string, field: string, file: string} $result */
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
        self::assertSame(['code' => Drafts::SAVED, 'campaign' => $id, 'field' => '', 'file' => ''], $result);
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

        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::newId(), 'field' => '', 'file' => ''], $again);
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

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'field' => '', 'file' => ''], $result);
        self::assertNotContains('update', array_column($this->drafts->writes, 'mode'));
        self::assertSame('3', $this->drafts->field(self::newId(), 'status_code.....'));
    }

    // storeItem() would move it into the campaign slice
    public function test_save_duplicateIdOfOtherSliceUnknown(): void
    {
        $this->drafts->draft(self::newId(), Sending::OTHER);

        $result = $this->saveNew(['isds_subject' => 'Výzva']);

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'field' => '', 'file' => ''], $result);
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

        self::assertSame(['code' => Actions::INPUT, 'campaign' => self::newId(), 'field' => Drafts::TO_HANDS, 'file' => ''], $refused);
        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::newId(), 'field' => '', 'file' => ''], $next);
        self::assertSame([self::newId()], array_keys($this->drafts->items));
    }

    // queued, then deleted for good: a stale form of it does not make the draft again under its run
    public function test_save_staleNonceAfterDeletionNotMadeAgain(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $this->drafts->runs = [self::newId()];
        $this->drafts->items = [];

        self::assertSame(['code' => SendStart::ALREADY, 'campaign' => self::newId(), 'field' => '', 'file' => ''], $this->saveNew(['isds_subject' => 'Výzva 2']));
        self::assertSame([[], 1], [$this->drafts->items, count($this->drafts->writes)]);
    }

    // ------------------------------------------------------------ a draft of the page

    public function test_save_updateNeverWritesStatusCode(): void
    {
        $this->drafts->draft(self::ITEM);

        $result = $this->save(['isds_subject' => 'Výzva', 'isds_body' => '<p>x</p>'], self::ITEM);

        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::ITEM, 'field' => '', 'file' => ''], $result);
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

    // ------------------------------------------------------------ the filter the page read

    /** the draft ITEM with a filter of many items chosen one by one - a copy for the unsent -, as a save stores it */
    private function draftWithChosen(): string
    {
        $filter = RecipientFilter::ofItems([self::ADAMOV, self::BENESOV])->toJson();
        $this->drafts->draft(self::ITEM);
        $this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_FILTER] = [$filter, 0];

        return $filter;
    }

    /** @return array<string, array{\Closure(string): string}> what a form tells of the filter of a page that did not read the one stored */
    public static function notSeen(): array
    {
        return [
            // a form of no page of the gate
            'none'        => [static fn(string $stored): string => ''],
            // another window saved another filter since
            'another one' => [static fn(string $stored): string => Drafts::filterSeen(Sending::filter())],
            'of no filter' => [static fn(string $stored): string => Drafts::filterSeen('')],
            'in capitals' => [static fn(string $stored): string => strtoupper(Drafts::filterSeen($stored))],
        ];
    }

    // saving the letter must not drop the recipients chosen - and the page tells the choice was not saved
    #[DataProvider('notSeen')]
    public function test_save_filterNotSeenKept(\Closure $seen): void
    {
        $stored = $this->draftWithChosen();

        $result = $this->save(['isds_subject' => 'Výzva', 'isds_membership' => 'all', 'isds_types' => [], 'isds_include' => '', 'isds_exclude' => '', 'isds_filter_seen' => $seen($stored)], self::ITEM);

        self::assertSame([Drafts::FILTER_CHANGED, self::ITEM], [$result['code'], $result['campaign']]);
        self::assertSame([Schema::CAMPAIGN_SUBJECT], $this->lastWritten(), 'the letter saved');
        self::assertSame($stored, $this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER));
    }

    public function test_save_filterNotSeenKeptOfNewDraft(): void
    {
        $result = $this->saveNew(['isds_membership' => 'all', 'isds_types' => ['mesto'], 'isds_filter_seen' => '']);

        self::assertSame(Drafts::FILTER_CHANGED, $result['code']);
        self::assertSame('', $this->drafts->field(self::newId(), Schema::CAMPAIGN_FILTER));
        self::assertSame('draft', $this->drafts->field(self::newId(), Schema::CAMPAIGN_STATE), 'the draft made');
    }

    // a second click before the page showed the first: the choice stored already, nothing lost
    public function test_save_sameChoiceOfStaleFormSaved(): void
    {
        $stored = $this->draftWithChosen();
        $this->drafts->writes = [];

        $result = $this->save(['isds_membership' => 'all', 'isds_types' => [], 'isds_include' => self::ADAMOV . '-' . self::BENESOV, 'isds_filter_seen' => Drafts::filterSeen('')], self::ITEM);

        self::assertSame(Drafts::SAVED, $result['code']);
        self::assertSame([[], $stored], [$this->drafts->writes, $this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER)]);
    }

    // a form saved again without loading the page (autosave): it shows the filter it saved only once it takes the answer
    public function test_save_filterSeenOfTheFilterStoredNow(): void
    {
        $this->saveNew(['isds_membership' => 'all', 'isds_types' => ['mesto']]);
        $first  = $this->drafts->field(self::newId(), Schema::CAMPAIGN_FILTER);
        $chosen = ['isds_membership' => 'all', 'isds_types' => ['mestocast'], 'isds_nonce' => self::NONCE];

        self::assertSame(Drafts::FILTER_CHANGED, $this->save($chosen + ['isds_filter_seen' => Drafts::filterSeen('')])['code']);
        self::assertSame($first, $this->drafts->field(self::newId(), Schema::CAMPAIGN_FILTER), 'the form as shown before the first save');

        self::assertSame(Drafts::SAVED, $this->save($chosen + ['isds_filter_seen' => Drafts::filterSeen($first)])['code']);
        self::assertSame(['mestocast'], json_decode($this->drafts->field(self::newId(), Schema::CAMPAIGN_FILTER), true)['types']);
    }

    // a filter the page cannot read: a choice made anew, with what tells the text stored, replaces it
    public function test_save_unreadableFilterReplacedByAChoiceMadeAnew(): void
    {
        $this->drafts->draft(self::ITEM);
        $broken = Sending::filter(['types' => ['kraj']]);
        $this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_FILTER] = [$broken, 0];

        self::assertSame(Drafts::SAVED, $this->save(['isds_subject' => 'Výzva'], self::ITEM)['code'], 'an autosave of the letter leaves it');
        self::assertSame($broken, $this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER));

        self::assertSame(Drafts::SAVED, $this->save(['isds_membership' => 'all', 'isds_types' => ['mesto'], 'isds_filter_seen' => Drafts::filterSeen($broken)], self::ITEM)['code']);
        self::assertSame(Sending::filter(), $this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER));
    }

    // the item a button picks goes into the lists the form shows - never into those stored
    public function test_save_pickOnPostedLists(): void
    {
        $this->draftWithChosen();
        $this->drafts->source = self::source();
        $post = ['isds_membership' => 'all', 'isds_types' => ['mesto'], 'isds_include' => self::BENESOV, 'isds_exclude' => self::CHEB];

        self::assertSame(Drafts::SAVED, $this->save($post + ['isds_pick' => 'include-' . strtoupper(self::CHEB)], self::ITEM)['code']);

        self::assertSame(
            ['types' => ['mesto'], 'membership' => 'all', 'include' => [self::BENESOV, self::CHEB], 'exclude' => []],
            json_decode($this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER), true),
        );
    }

    // the form without its lists would pick into the lists stored, which the page did not show
    public function test_save_pickWithoutMembershipInput(): void
    {
        $this->draftWithChosen();
        $this->drafts->source = self::source();

        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::RECIPIENTS, $this->save(['isds_subject' => 'Výzva', 'isds_include' => '', 'isds_pick' => 'exclude-' . self::ADAMOV], self::ITEM));
    }

    /** @return array<string, array{mixed}> */
    public static function noPicks(): array
    {
        return [
            'no such pick'   => ['add-' . self::CHEB],
            'no id'          => ['include-'],
            'an id and more' => ['include-' . self::CHEB . '-' . self::ADAMOV],
            'a list'         => [['include-' . self::CHEB]],
            'empty'          => [''],
        ];
    }

    #[DataProvider('noPicks')]
    public function test_save_noPickInput(mixed $pick): void
    {
        $this->draftWithChosen();
        $this->drafts->source = self::source();

        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::RECIPIENTS, $this->save(['isds_subject' => 'Výzva', 'isds_membership' => 'all', 'isds_pick' => $pick], self::ITEM));
    }

    /** @return array<string, array{string}> */
    public static function goneItems(): array
    {
        return [
            'an item of another slice' => [self::OTHER_SLICE_ITEM],
            'an item put in the trash' => [self::TRASHED],
            'an item deleted'          => [str_repeat('f', 32)],
        ];
    }

    // the page showed it, it is gone since: the letter is saved, the choice is not - and the page says so
    #[DataProvider('goneItems')]
    public function test_save_pickOtherSliceInput(string $item): void
    {
        $stored = $this->draftWithChosen();
        $this->drafts->source = self::source();

        $result = $this->save(['isds_subject' => 'Výzva', 'isds_membership' => 'all', 'isds_types' => [], 'isds_include' => self::ADAMOV, 'isds_pick' => "include-$item"], self::ITEM);

        self::assertSame([Drafts::FILTER_CHANGED, self::ITEM], [$result['code'], $result['campaign']]);
        self::assertSame([Schema::CAMPAIGN_SUBJECT], $this->lastWritten());
        self::assertSame($stored, $this->drafts->field(self::ITEM, Schema::CAMPAIGN_FILTER));
    }

    public function test_save_pickOverMaxInput(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->source = self::source();
        $ids = array_map(static fn(int $i): string => sprintf('b%031x', $i), range(1, Setup::MAX_RECIPIENTS));

        $this->assertRefusedNothingWritten(Actions::INPUT, Drafts::TOO_MANY, $this->save(['isds_membership' => 'all', 'isds_exclude' => implode('-', $ids), 'isds_pick' => 'exclude-' . self::CHEB], self::ITEM));
    }

    // the stored filter is read only under the lock of the draft, a pick asks only the source slice
    public function test_save_neverReadsStoredFilterOutsideLock(): void
    {
        $this->draftWithChosen();
        $this->drafts->source = self::source();
        FakeRecipients::forget();

        $this->save(['isds_subject' => 'Výzva', 'isds_membership' => 'all', 'isds_include' => self::ADAMOV, 'isds_pick' => 'include-' . self::CHEB], self::ITEM);

        self::assertSame([], $this->drafts->readUnlocked);
        self::assertSame([[Sending::SOURCE, [], [self::CHEB], [], 0]], FakeRecipients::$searches, 'the item picked, of the source slice');
        self::assertSame([], FakeRecipients::$read);
    }

    /** the source slice: ADAMOV, BENESOV and CHEB active, TRASHED in the trash; an item of another slice */
    private static function source(): FakeRecipients
    {
        return new FakeRecipients([
            self::ADAMOV           => Sending::item('Adamov', 'mesto', '1', 'abc1234'),
            self::BENESOV          => Sending::item('Benešov', 'mesto', '0', 'abc2345'),
            self::CHEB             => Sending::item('Cheb', 'mesto', '0', 'abc3456'),
            self::TRASHED          => ['active' => false] + Sending::item('Dačice', 'mesto', '0', 'abc4567'),
            self::OTHER_SLICE_ITEM => ['slice' => Sending::OTHER] + Sending::item('Eger', 'mesto', '0', 'abc5678'),
        ]);
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

        self::assertSame(['code' => SendStart::NOT_DRAFT, 'campaign' => self::ITEM, 'field' => '', 'file' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
        self::assertSame([], $this->drafts->writes);
    }

    // the item edited back to draft: its sending is a record still
    public function test_save_draftWithRunRefused(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->runs = [self::ITEM];

        self::assertSame(['code' => SendStart::ALREADY, 'campaign' => self::ITEM, 'field' => '', 'file' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
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

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'field' => '', 'file' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
        self::assertSame([], $this->drafts->writes);
    }

    // queueing holds the lock of the campaign while it freezes the letter
    public function test_save_busyWhileQueued(): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->lockedByOther = true;

        self::assertSame(['code' => SendStart::BUSY, 'campaign' => self::ITEM, 'field' => '', 'file' => ''], $this->save(['isds_subject' => 'Výzva'], self::ITEM));
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

    // ------------------------------------------------------------ attachments

    /**
     * isds_files[] as PHP gives it, each file received
     * @param list<array{0: string, 1: string, 2?: int, 3?: string}> $files name, content, error, the type the browser says
     * @return array<string, array<string, list<int|string>>>
     */
    private function files(array $files): array
    {
        $posted = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as $file) {
            [$name, $content]     = $file;
            $error                = $file[2] ?? UPLOAD_ERR_OK;
            $posted['name'][]     = $name;
            $posted['type'][]     = $file[3] ?? 'application/pdf';
            $posted['tmp_name'][] = ($error === UPLOAD_ERR_OK) ? $this->drafts->receive($content) : '';
            $posted['error'][]    = $error;
            $posted['size'][]     = ($error === UPLOAD_ERR_OK) ? strlen($content) : 0;
        }

        return ['isds_files' => $posted];
    }

    /** @param list<string> $urls the attachments of the draft ITEM */
    private function draftWithFiles(array $urls): void
    {
        $this->drafts->draft(self::ITEM);
        $this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_FILES] = [$urls[0] ?? '', 0];
        $this->drafts->items[self::ITEM]['values'][Schema::CAMPAIGN_FILES] = $urls;
    }

    private static function url(string $name): string
    {
        return MemoryDrafts::URL . "/$name";
    }

    /** @param array{code: string, campaign: string, field: string, file: string} $result */
    private function assertFileRefused(string $field, string $file, array $result): void
    {
        self::assertSame([Actions::INPUT, $field, $file], [$result['code'], $result['field'], $result['file']]);
        self::assertSame([[], []], [$this->drafts->writes, $this->drafts->disk], 'nothing written, no file left');
    }

    public function test_files_addedToDraft(): void
    {
        $this->draftWithFiles([self::url('mapa.png')]);

        $result = $this->drafts->save(Sending::page(), self::ITEM, ['isds_subject' => 'Výzva'], $this->files([['Pozvanka.pdf', self::PDF], ['plan.png', base64_decode(self::PNG)]]));

        self::assertSame(['code' => Drafts::SAVED, 'campaign' => self::ITEM, 'field' => '', 'file' => ''], $result);
        self::assertSame([self::url('mapa.png'), self::url('pozvanka.pdf'), self::url('plan.png')], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
        self::assertSame([Schema::CAMPAIGN_SUBJECT, Schema::CAMPAIGN_FILES], $this->lastWritten());
        self::assertSame([MemoryDrafts::DIR . '/pozvanka.pdf' => self::PDF, MemoryDrafts::DIR . '/plan.png' => base64_decode(self::PNG)], $this->drafts->disk);
        self::assertSame([], $this->drafts->received, 'each moved');
    }

    // a name some file of the slice has already - an attachment of another draft - gets a number
    public function test_files_sameNameNeverReplacesAFile(): void
    {
        $this->draftWithFiles([]);
        $this->drafts->disk[MemoryDrafts::DIR . '/pozvanka.pdf'] = 'of another draft';

        $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['pozvanka.pdf', self::PDF]]));

        self::assertSame([self::url('pozvanka_1.pdf')], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
        self::assertSame('of another draft', $this->drafts->disk[MemoryDrafts::DIR . '/pozvanka.pdf']);
    }

    // the first save of a form may carry files; the next of the same form adds into the draft it made
    public function test_files_newDraftWithFiles(): void
    {
        $first = $this->drafts->save(Sending::page(), '', ['isds_nonce' => self::NONCE, 'isds_subject' => 'Výzva'], $this->files([['pozvanka.pdf', self::PDF]]));
        $again = $this->drafts->save(Sending::page(), '', ['isds_nonce' => self::NONCE], $this->files([['plan.png', base64_decode(self::PNG)]]));

        self::assertSame([Drafts::SAVED, Drafts::SAVED], [$first['code'], $again['code']]);
        self::assertSame(['insert_if_new', 'update'], array_column($this->drafts->writes, 'mode'));
        self::assertSame([self::url('pozvanka.pdf'), self::url('plan.png')], $this->drafts->values(self::newId(), Schema::CAMPAIGN_FILES));
        self::assertSame(['1', 'draft'], [$this->drafts->field(self::newId(), 'status_code.....'), $this->drafts->field(self::newId(), Schema::CAMPAIGN_STATE)]);
    }

    /** @return array<string, array{string, string}> the name posted, the name stored */
    public static function names(): array
    {
        return [
            'a server script inside'  => ['smlouva.php.pdf', 'smlouva_php.pdf'],
            'in capitals'             => ['Smlouva.PHP.PDF', 'smlouva_php.pdf'],
            'many dots'               => ['a.b.c.d.pdf', 'a_b_c_d.pdf'],
            'a leading dot'           => ['.htaccess.pdf', 'htaccess.pdf'],
            'a path'                  => ['../../etc/smlouva.pdf', 'etc-smlouva.pdf'],
            'spaces'                  => ['Vyzva k ucasti.pdf', 'vyzva-k-ucasti.pdf'],
        ];
    }

    // Apache's mod_mime runs "x.php.pdf" as PHP wherever .php has a handler
    #[DataProvider('names')]
    public function test_files_nameKeepsOneDot(string $posted, string $stored): void
    {
        $this->draftWithFiles([]);

        self::assertSame(Drafts::SAVED, $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([[$posted, self::PDF]]))['code']);
        self::assertSame([MemoryDrafts::DIR . "/$stored"], array_keys($this->drafts->disk));
        self::assertSame([self::url($stored)], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
    }

    // whatever the charset of the language: safe letters, one dot, the extension kept
    public function test_files_nameWithDiacriticsInSafeLetters(): void
    {
        $this->draftWithFiles([]);

        self::assertSame(Drafts::SAVED, $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['Příloha č. 1.PDF', self::PDF]]))['code']);
        self::assertCount(1, $this->drafts->disk);
        self::assertMatchesRegularExpression('~^' . preg_quote(MemoryDrafts::DIR, '~') . '/p[a-z0-9_-]*\.pdf\z~', (string) array_key_first($this->drafts->disk));
    }

    /** @return array<string, array{string, string, string, string}> name, content, the type the browser says, what INPUT names ('' = taken) */
    public static function contents(): array
    {
        $html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';

        return [
            'a page said to be a pdf'      => ['pozvanka.pdf', $html, 'application/pdf', Drafts::FILE_CONTENT],
            'a picture with scripts as png' => ['plan.png', self::SVG, 'image/png', Drafts::FILE_CONTENT],
            'a page as plain text'         => ['poznamky.txt', $html, 'text/plain', Drafts::FILE_CONTENT],
            'a png said to be a page'      => ['plan.png', (string) base64_decode(self::PNG), 'text/html', ''],
            'a pdf said to be a page'      => ['pozvanka.pdf', self::PDF, 'text/html', ''],
        ];
    }

    // what the content is, never what the browser says
    #[DataProvider('contents')]
    public function test_files_mimeFromFinfoNotClient(string $name, string $content, string $type, string $field): void
    {
        $this->draftWithFiles([]);

        $result = $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([[$name, $content, UPLOAD_ERR_OK, $type]]));

        if ($field === '') {
            self::assertSame([Drafts::SAVED, [self::url($name)]], [$result['code'], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES)]);
        } else {
            $this->assertFileRefused($field, $name, $result);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function types(): array
    {
        return [
            'zip'        => ['prilohy.zip', "PK\x03\x04"],
            'asic'       => ['podpis.asice', "PK\x03\x04"],
            'html'       => ['dopis.html', '<html></html>'],
            'htm'        => ['dopis.htm', '<html></html>'],
            'xml'        => ['data.xml', '<?xml version="1.0"?><a/>'],
            'fo'         => ['formular.fo', '<?xml version="1.0"?><a/>'],
            'zfo'        => ['zprava.zfo', "PK\x03\x04"],
            'svg'        => ['logo.svg', self::SVG],
            'webp'       => ['foto.webp', 'RIFF'],
            'php'        => ['skript.php', '<?php echo 1;'],
            'no type'    => ['README', 'text'],
        ];
    }

    // what the editor uploads and a data message carries: no archive ISDS checks inside, no page, no XML
    #[DataProvider('types')]
    public function test_files_zipHtmlXmlFoZfoSvgRefused(string $name, string $content): void
    {
        $this->draftWithFiles([]);

        $this->assertFileRefused(Drafts::FILE_TYPE, strtolower($name), $this->drafts->save(Sending::page(), self::ITEM, ['isds_subject' => 'Výzva'], $this->files([['pozvanka.pdf', self::PDF], [$name, $content]])));
        self::assertSame([[], []], [$this->drafts->read, $this->drafts->lockLog], 'refused by its name, before the lock');
    }

    // the letter is the first file of the message
    public function test_files_tooManyRefused(): void
    {
        $urls = array_map(static fn(int $i): string => self::url("priloha-$i.pdf"), range(1, Message::MAX_FILES - 2));
        $this->draftWithFiles($urls);

        $this->assertFileRefused(Drafts::TOO_MANY_FILES, '', $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['a.pdf', self::PDF], ['b.pdf', self::PDF]])));
        self::assertSame([], $this->drafts->read, 'counted before a file is read');

        $result = $this->drafts->save(Sending::page(), self::ITEM, ['isds_remove' => [$urls[0]]], $this->files([['a.pdf', self::PDF], ['b.pdf', self::PDF]]));
        self::assertSame(Drafts::SAVED, $result['code']);
        self::assertCount(Message::MAX_FILES - 1, $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));

        // more than a message carries in one form: refused before the lock
        $this->drafts->writes = $this->drafts->lockLog = [];
        $this->drafts->disk   = [];
        $many                 = $this->files(array_fill(0, Message::MAX_FILES, ['c.pdf', self::PDF]));
        $this->assertFileRefused(Drafts::TOO_MANY_FILES, '', $this->drafts->save(Sending::page(), self::ITEM, [], $many));
        self::assertSame([], $this->drafts->lockLog);
    }

    // PHP took none of the file: nothing of the form is saved, the other files neither
    public function test_files_iniSizeOfOneFileRefused(): void
    {
        $this->draftWithFiles([]);

        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
            $result = $this->drafts->save(Sending::page(), self::ITEM, ['isds_subject' => 'Výzva'], $this->files([['pozvanka.pdf', self::PDF], ['Velka mapa.pdf', '', $error]]));

            $this->assertFileRefused(Drafts::FILE_SIZE, 'velka-mapa.pdf', $result);
            self::assertSame([], $this->drafts->lockLog, 'refused before the lock');
        }
    }

    // larger than a data message: never read
    public function test_files_largerThanAMessageRefused(): void
    {
        $this->draftWithFiles([]);
        $files = $this->files([['mapa.pdf', self::PDF]]);
        $files['isds_files']['size'][0] = Message::MAX_BYTES + 1;

        $this->assertFileRefused(Drafts::FILE_SIZE, 'mapa.pdf', $this->drafts->save(Sending::page(), self::ITEM, [], $files));
        self::assertSame([], $this->drafts->read);
    }

    /** @return array<string, array{int, string, string}> the error PHP gives, the content, what INPUT names */
    public static function notReceived(): array
    {
        return [
            'cut short' => [UPLOAD_ERR_PARTIAL, '', Drafts::FILE_LOST],
            'empty'     => [UPLOAD_ERR_OK, '', Drafts::FILE_EMPTY],
        ];
    }

    #[DataProvider('notReceived')]
    public function test_files_notWholeRefused(int $error, string $content, string $field): void
    {
        $this->draftWithFiles([]);

        $this->assertFileRefused($field, 'mapa.pdf', $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['mapa.pdf', $content, $error]])));
    }

    // a temporary name the request did not upload - is_uploaded_file() says no
    public function test_files_notUploadedInTheRequestRefused(): void
    {
        $this->draftWithFiles([]);
        $files = $this->files([['mapa.pdf', self::PDF]]);
        $files['isds_files']['tmp_name'][0] = '/etc/passwd';

        $this->assertFileRefused(Drafts::FILE_LOST, 'mapa.pdf', $this->drafts->save(Sending::page(), self::ITEM, [], $files));
    }

    // no temporary directory, a full disk: the server, not the file - an internal error, logged
    public function test_files_serverErrorThrows(): void
    {
        $this->draftWithFiles([]);

        $this->expectException(\RuntimeException::class);
        $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['mapa.pdf', '', UPLOAD_ERR_CANT_WRITE]]));
    }

    public function test_files_removeOnlyOwnUrl(): void
    {
        $this->draftWithFiles([self::url('a.pdf'), self::url('b.pdf')]);

        $result = $this->drafts->save(Sending::page(), self::ITEM, ['isds_remove' => [self::url('b.pdf'), self::url('cizi.pdf'), 'https://cizi.example/a.pdf']], []);

        self::assertSame(Drafts::SAVED, $result['code']);
        self::assertSame([self::url('a.pdf')], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));

        // an attachment the draft does not have - of another draft, removed already: nothing to write
        $this->drafts->writes = [];
        self::assertSame(Drafts::SAVED, $this->drafts->save(Sending::page(), self::ITEM, ['isds_remove' => [self::url('b.pdf'), self::url('cizi.pdf')]], ['isds_files' => ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]]])['code']);
        self::assertSame([], $this->drafts->writes);
    }

    // a copy of the draft may attach the same file
    public function test_files_removedFileStaysOnDisk(): void
    {
        $this->draftWithFiles([self::url('a.pdf')]);
        $this->drafts->disk = [MemoryDrafts::DIR . '/a.pdf' => self::PDF];

        self::assertSame(Drafts::SAVED, $this->drafts->save(Sending::page(), self::ITEM, ['isds_remove' => [self::url('a.pdf')]], [])['code']);

        self::assertSame([], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
        self::assertSame([MemoryDrafts::DIR . '/a.pdf' => self::PDF], $this->drafts->disk);
    }

    // a file refused by its content: the letter of the same form is not saved, no file stays behind
    public function test_files_refusedUploadWritesNothing(): void
    {
        $this->draftWithFiles([self::url('a.pdf')]);

        $result = $this->drafts->save(Sending::page(), self::ITEM, ['isds_subject' => 'Výzva', 'isds_remove' => [self::url('a.pdf')]], $this->files([['pozvanka.pdf', self::PDF], ['plan.png', self::SVG]]));

        $this->assertFileRefused(Drafts::FILE_CONTENT, 'plan.png', $result);
        self::assertSame([self::url('a.pdf')], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
        self::assertSame('', $this->drafts->field(self::ITEM, Schema::CAMPAIGN_SUBJECT));
    }

    /** @return array<string, array{string, bool}> what fails, a new draft */
    public static function failures(): array
    {
        $rows = [];
        foreach (['move', 'url', 'store', 'store throws'] as $fails) {
            $rows["$fails of a draft"]     = [$fails, false];
            $rows["$fails of a new draft"] = [$fails, true];
        }

        return $rows;
    }

    // a file moved for a save that failed is removed again
    #[DataProvider('failures')]
    public function test_files_failureLeavesNoFile(string $fails, bool $new): void
    {
        if (!$new) {
            $this->draftWithFiles([]);
        }
        $files = $this->files([['pozvanka.pdf', self::PDF], ['plan.png', base64_decode(self::PNG)]]);
        $this->drafts->moveFails   = ($fails === 'move') ? $files['isds_files']['tmp_name'][1] : '';
        $this->drafts->urlFails    = ($fails === 'url');
        $this->drafts->storeFails  = ($fails === 'store');
        $this->drafts->storeThrows = ($fails === 'store throws');

        try {
            $this->drafts->save(Sending::page(), $new ? '' : self::ITEM, $new ? ['isds_nonce' => self::NONCE] : [], $files);
            self::fail('not saved');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->drafts->disk);
        self::assertSame([], $this->drafts->values($new ? self::newId() : self::ITEM, Schema::CAMPAIGN_FILES));
    }

    // a field left empty in the editor of the slice holds an empty value: no attachment, not written again
    public function test_files_emptyValueIsNoAttachment(): void
    {
        $urls = array_map(static fn(int $i): string => self::url("priloha-$i.pdf"), range(1, Message::MAX_FILES - 2));
        $this->draftWithFiles(['', ...$urls]);

        self::assertSame(Drafts::SAVED, $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['a.pdf', self::PDF]]))['code']);
        self::assertSame([...$urls, self::url('a.pdf')], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
    }

    // an <input type="file"> of the template left empty gives the field (fil) nothing to take
    public function test_files_emptyInputOfAnotherNameTaken(): void
    {
        $this->draftWithFiles([]);
        $empty = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $files = ['logo' => $empty, 'more' => array_map(static fn(mixed $part): array => [$part], $empty)] + $this->files([['a.pdf', self::PDF]]);

        self::assertSame(Drafts::SAVED, $this->drafts->save(Sending::page(), self::ITEM, [], $files)['code']);
        self::assertSame([self::url('a.pdf')], $this->drafts->values(self::ITEM, Schema::CAMPAIGN_FILES));
    }

    /** @return array<string, array{string, bool, string}> state, queued once, what save says */
    public static function noDraft(): array
    {
        return [
            'queued'        => ['queued', false, SendStart::NOT_DRAFT],
            'with a run'    => ['draft', true, SendStart::ALREADY],
            'in the trash'  => ['trash', false, SendStart::UNKNOWN],
        ];
    }

    // a file is read, let alone moved, only for a draft that takes it
    #[DataProvider('noDraft')]
    public function test_files_notDraftCheckedBeforeUpload(string $state, bool $queued, string $code): void
    {
        $this->drafts->draft(self::ITEM, status: ($state === 'trash') ? 3 : 1, state: ($state === 'trash') ? 'draft' : $state);
        $this->drafts->runs = $queued ? [self::ITEM] : [];

        self::assertSame($code, $this->drafts->save(Sending::page(), self::ITEM, [], $this->files([['pozvanka.pdf', self::PDF]]))['code']);
        self::assertSame([[], [], []], [$this->drafts->read, $this->drafts->disk, $this->drafts->writes]);
    }

    // the form of a new draft queued since: its files are not read either
    public function test_files_staleNonceAfterQueueReadsNoFile(): void
    {
        $this->saveNew(['isds_subject' => 'Výzva']);
        $this->drafts->items[self::newId()]['fields'][Schema::CAMPAIGN_STATE] = ['queued', 0];

        $result = $this->drafts->save(Sending::page(), '', ['isds_nonce' => self::NONCE], $this->files([['pozvanka.pdf', self::PDF]]));

        self::assertSame(SendStart::NOT_DRAFT, $result['code']);
        self::assertSame([[], []], [$this->drafts->read, $this->drafts->disk]);
        self::assertCount(1, $this->drafts->writes);
    }

    /** @return array<string, array{array<string, mixed>, \Closure(self): array<string, mixed>}> */
    public static function notAsPosted(): array
    {
        $file = static fn(self $test): array => $test->files([['pozvanka.pdf', self::PDF]]);

        return [
            // the insert function of the field (fil) would take it by its own rules
            'a file of another field'    => [[], static fn(self $test): array => ['v66696c652e2e2e2e2e2e2e2e2e2e2e2ex' => $file($test)['isds_files']]],
            'a single file of another'   => [[], static fn(self $test): array => ['logo' => array_map(static fn(array $part): mixed => $part[0], $file($test)['isds_files'])]],
            'the remove as a text'       => [['isds_remove' => self::url('a.pdf')], static fn(): array => []],
            'the remove of a list'       => [['isds_remove' => [[self::url('a.pdf')]]], static fn(): array => []],
            'files of a deeper name'     => [[], static fn(self $test): array => ['isds_files' => array_map(static fn(array $part): array => ['x' => $part], $file($test)['isds_files'])]],
        ];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('notAsPosted')]
    public function test_files_notAsThePagePostsRefused(array $post, \Closure $files): void
    {
        $this->draftWithFiles([self::url('a.pdf')]);

        $this->assertFileRefused(Drafts::FILES, '', $this->drafts->save(Sending::page(), self::ITEM, $post + ['isds_subject' => 'Výzva'], $files($this)));
        self::assertSame([], $this->drafts->lockLog);
    }

    // ------------------------------------------------------------ a copy

    /**
     * A campaign of the page sent and done: its letter with two attachments, its filter, and the fields
     * its sending wrote
     */
    private function sentCampaign(int $status = 1): void
    {
        $this->drafts->draft(self::ITEM, status: $status, state: Schema::CAMPAIGN_STATE_DONE);
        $this->drafts->items[self::ITEM]['fields'] += [
            Schema::CAMPAIGN_SUBJECT       => ['Výzva', 0],
            Schema::CAMPAIGN_TO_HANDS      => ['starosta', 0],
            Schema::CAMPAIGN_BODY          => ['<p>Vážení</p>', FLAG_HTML],
            Schema::CAMPAIGN_FILES         => [self::url('a.pdf'), 0],
            Schema::CAMPAIGN_FILTER        => [Sending::filter(['exclude' => [self::ADAMOV]]), 0],
            Schema::CAMPAIGN_TEMPLATE      => ['ca000000000000000000000000000c00', 0],
            Schema::CAMPAIGN_ERROR_TEXT    => ['ISDS_LOGIN', 0],
            Schema::CAMPAIGN_ENVIRONMENT   => ['test', 0],
            Schema::CAMPAIGN_SENDER_BOX    => ['kv62bqf', 0],
            Schema::CAMPAIGN_RECIPIENTS    => ['3', 0],
            Schema::CAMPAIGN_SENT_BY       => ['1', 0],
            Schema::CAMPAIGN_SENT_BY_NAME  => ['Jan Novák', 0],
            Schema::CAMPAIGN_STARTED_AT    => ['1900000000', 0],
            Schema::CAMPAIGN_FINISHED_AT   => ['1900003600', 0],
            'posted_by.......'             => ['1', 0],
        ];
        $this->drafts->items[self::ITEM]['values'][Schema::CAMPAIGN_FILES] = [self::url('a.pdf'), '', self::url('b.pdf')];
    }

    /**
     * @param list<string> $unsent
     * @return array{code: string, campaign: string, made: bool}
     */
    private function copy(string $scope = Drafts::ALL, array $unsent = [], string $nonce = self::NONCE): array
    {
        return $this->drafts->copy(Sending::page(), self::ITEM, $scope, ['isds_nonce' => $nonce], $unsent);
    }

    private static function copyId(string $scope = Drafts::ALL): string
    {
        return Drafts::copyId(Sending::page(), self::ITEM, $scope, self::NONCE);
    }

    // the letter as it is, the recipients by the filter the source stores, its attachments by the same URLs
    public function test_copy_wholeKeepsFilterText(): void
    {
        $this->sentCampaign();

        $result = $this->copy();

        $id = self::copyId();
        self::assertSame(['code' => Drafts::CLONED, 'campaign' => $id, 'made' => true], $result);
        self::assertSame([['insert_if_new', $id, Sending::CAMPAIGN, true, [true, false, false, false]]], array_map(static fn(array $w): array => [$w['mode'], $w['id'], $w['slice'], $w['open'], $w['flags']], $this->drafts->writes));
        self::assertSame(['1', 'draft', 'Výzva', 'starosta', Sending::filter(['exclude' => [self::ADAMOV]])], [
            $this->drafts->field($id, 'status_code.....'),
            $this->drafts->field($id, Schema::CAMPAIGN_STATE),
            $this->drafts->field($id, Schema::CAMPAIGN_SUBJECT),
            $this->drafts->field($id, Schema::CAMPAIGN_TO_HANDS),
            $this->drafts->field($id, Schema::CAMPAIGN_FILTER),
        ]);
        self::assertSame(['<p>Vážení</p>', FLAG_HTML], $this->drafts->items[$id]['fields'][Schema::CAMPAIGN_BODY], 'the body with its flag');
        self::assertSame([self::url('a.pdf'), self::url('b.pdf')], $this->drafts->values($id, Schema::CAMPAIGN_FILES), 'the same files, none copied');
        self::assertSame(['lock ' . SendRun::lockName($id), 'unlock ' . SendRun::lockName($id)], $this->drafts->lockLog);
    }

    // a plain-text body stays plain text: as HTML it would lose its lines
    public function test_copy_plainBodyStaysPlain(): void
    {
        $this->sentCampaign();
        $this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_BODY] = ["Vážení\nstarosto", 0];

        $this->copy();

        self::assertSame(["Vážení\nstarosto", 0], $this->drafts->items[self::copyId()]['fields'][Schema::CAMPAIGN_BODY]);
    }

    // a copy is a draft: nothing of the sending of its source, nor its author - storeItem() writes the login
    public function test_copy_neverCopiesStateOrRunFields(): void
    {
        $this->sentCampaign();

        $this->copy();

        self::assertSame(
            ['status_code.....', Schema::CAMPAIGN_STATE, Schema::CAMPAIGN_TEMPLATE, Schema::CAMPAIGN_SUBJECT, Schema::CAMPAIGN_TO_HANDS, Schema::CAMPAIGN_BODY, Schema::CAMPAIGN_FILES, Schema::CAMPAIGN_FILTER],
            $this->lastWritten(),
        );
        self::assertSame(Schema::CAMPAIGN_STATE_DRAFT, $this->drafts->field(self::copyId(), Schema::CAMPAIGN_STATE));
    }

    // the copy names its source, not the source of its source
    public function test_copy_templateIsTheSource(): void
    {
        $this->sentCampaign();

        $this->copy();

        self::assertSame(self::ITEM, $this->drafts->field(self::copyId(), Schema::CAMPAIGN_TEMPLATE));
    }

    // a source with no filter stored: none in the copy either - sending refuses it till they are chosen
    public function test_copy_noFilterStoresNone(): void
    {
        $this->sentCampaign();
        unset($this->drafts->items[self::ITEM]['fields'][Schema::CAMPAIGN_FILTER]);

        $this->copy();

        self::assertNotContains(Schema::CAMPAIGN_FILTER, $this->lastWritten());
    }

    // only those the sending left unsent, by their items; the letter as in a whole copy
    public function test_copy_unsentFilterOfTheItems(): void
    {
        $this->sentCampaign(status: 3);
        $unsent = [self::ADAMOV, 'a0000000000000000000000000000002'];

        $result = $this->copy(Drafts::UNSENT, $unsent);

        $id = self::copyId(Drafts::UNSENT);
        self::assertSame(['code' => Drafts::CLONED, 'campaign' => $id, 'made' => true], $result);
        self::assertNotSame(self::copyId(), $id, 'another scope, another copy');
        self::assertSame(json_encode(['types' => [], 'membership' => 'all', 'include' => $unsent, 'exclude' => []]), $this->drafts->field($id, Schema::CAMPAIGN_FILTER));
        self::assertSame(['1', 'Výzva', self::ITEM], [$this->drafts->field($id, 'status_code.....'), $this->drafts->field($id, Schema::CAMPAIGN_SUBJECT), $this->drafts->field($id, Schema::CAMPAIGN_TEMPLATE)]);
    }

    // the thousands a large sending may leave unsent: none is cut
    public function test_copy_unsentOfMaxRecipients(): void
    {
        $this->sentCampaign();
        $unsent = array_map(static fn(int $i): string => sprintf('a%031x', $i), range(1, Setup::MAX_RECIPIENTS));

        $this->copy(Drafts::UNSENT, $unsent);

        self::assertSame($unsent, RecipientFilter::fromJson($this->drafts->field(self::copyId(Drafts::UNSENT), Schema::CAMPAIGN_FILTER), Sending::page())->include);
    }

    // a double click, or the same form posted again: the one copy, told as made once
    public function test_copy_doubleClickOneCopy(): void
    {
        $this->sentCampaign();

        $first  = $this->copy();
        $second = $this->copy();

        self::assertSame(['code' => Drafts::CLONED, 'campaign' => self::copyId(), 'made' => false], $second);
        self::assertTrue($first['made']);
        self::assertSame([self::ITEM, self::copyId()], array_keys($this->drafts->items));
        self::assertSame(['insert_if_new'], array_column($this->drafts->writes, 'mode'), 'nothing written again');
    }

    /** @return array<string, array{string, bool, string}> the state of the copy since, whether it has a run, the code */
    public static function copiesQueued(): array
    {
        return [
            'queued'                => [Schema::CAMPAIGN_STATE_QUEUED, true, SendStart::NOT_DRAFT],
            'sent'                  => [Schema::CAMPAIGN_STATE_DONE, true, SendStart::NOT_DRAFT],
            'put back to draft'     => [Schema::CAMPAIGN_STATE_DRAFT, true, SendStart::ALREADY],
        ];
    }

    // the form shown before the copy was queued: told as a save of it is, never made or changed again
    #[DataProvider('copiesQueued')]
    public function test_copy_staleFormAfterCopyQueued(string $state, bool $run, string $code): void
    {
        $this->sentCampaign();
        $this->copy();
        $this->drafts->items[self::copyId()]['fields'][Schema::CAMPAIGN_STATE] = [$state, 0];
        $this->drafts->runs = $run ? [self::copyId()] : [];
        $before             = $this->drafts->items[self::copyId()];

        self::assertSame(['code' => $code, 'campaign' => self::copyId(), 'made' => false], $this->copy());
        self::assertSame([['insert_if_new'], $before], [array_column($this->drafts->writes, 'mode'), $this->drafts->items[self::copyId()]]);
    }

    // queued, then deleted for good: a stale form does not make it again under its run
    public function test_copy_staleFormAfterDeletedWithRun(): void
    {
        $this->sentCampaign();
        $this->copy();
        $this->drafts->runs = [self::copyId()];
        unset($this->drafts->items[self::copyId()]);

        self::assertSame(['code' => SendStart::ALREADY, 'campaign' => self::copyId(), 'made' => false], $this->copy());
        self::assertSame([[self::ITEM], 1], [array_keys($this->drafts->items), count($this->drafts->writes)]);
    }

    /** @return array<string, array{\Closure(MemoryDrafts, string): void}> an item under the id of the copy that is no copy of the source made by the page */
    public static function notOwnCopies(): array
    {
        return [
            'of another slice'       => [static fn(MemoryDrafts $d, string $id) => $d->items[$id] = ['slice' => Sending::OTHER, 'fields' => [Schema::CAMPAIGN_TEMPLATE => [self::ITEM, 0]]]],
            'of another source'      => [static fn(MemoryDrafts $d, string $id) => $d->items[$id] = ['slice' => Sending::CAMPAIGN, 'fields' => ['status_code.....' => ['1', 0], Schema::CAMPAIGN_TEMPLATE => ['ca000000000000000000000000000c09', 0]]]],
            'of no source'           => [static fn(MemoryDrafts $d, string $id) => $d->draft($id)],
            'the copy in the trash'  => [static fn(MemoryDrafts $d, string $id) => $d->items[$id] = ['slice' => Sending::CAMPAIGN, 'fields' => ['status_code.....' => ['3', 0], Schema::CAMPAIGN_TEMPLATE => [self::ITEM, 0]]]],
        ];
    }

    // the id of an item that only shares it is never told, nor the item changed
    #[DataProvider('notOwnCopies')]
    public function test_copy_duplicateReturnsOnlyOwnCopy(\Closure $item): void
    {
        $this->sentCampaign();
        $item($this->drafts, self::copyId());
        $before = $this->drafts->items[self::copyId()];

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'made' => false], $this->copy());
        self::assertSame($before, $this->drafts->items[self::copyId()]);
        self::assertSame([], $this->drafts->writes);
    }

    /** @return array<string, array{\Closure(MemoryDrafts): void, string}> */
    public static function sourcesNotOfThePage(): array
    {
        return [
            'none'                    => [static fn(MemoryDrafts $d) => null, Drafts::ALL],
            'of another slice'        => [static fn(MemoryDrafts $d) => $d->draft(self::ITEM, Sending::OTHER), Drafts::ALL],
            'of another slice, unsent' => [static fn(MemoryDrafts $d) => $d->draft(self::ITEM, Sending::OTHER), Drafts::UNSENT],
            'in the trash'            => [static fn(MemoryDrafts $d) => $d->draft(self::ITEM, status: 3), Drafts::ALL],
        ];
    }

    #[DataProvider('sourcesNotOfThePage')]
    public function test_copy_sourceNotOfThePageUnknown(\Closure $source, string $scope): void
    {
        $source($this->drafts);

        self::assertSame(['code' => SendStart::UNKNOWN, 'campaign' => '', 'made' => false], $this->copy($scope, [self::ADAMOV]));
        self::assertSame([], $this->drafts->writes);
    }

    /** @return array<string, array{string, string}> */
    public static function badCopyForms(): array
    {
        return ['no scope' => ['', self::NONCE], 'another scope' => ['one', self::NONCE], 'a short nonce' => [Drafts::ALL, 'abc'], 'capitals' => [Drafts::ALL, strtoupper(self::NONCE)]];
    }

    #[DataProvider('badCopyForms')]
    public function test_copy_badScopeOrNonceIsInput(string $scope, string $nonce): void
    {
        $this->sentCampaign();

        self::assertSame(['code' => Actions::INPUT, 'campaign' => '', 'made' => false], $this->copy($scope, nonce: $nonce));
        self::assertSame([[], []], [$this->drafts->writes, $this->drafts->lockLog]);
    }

    public function test_copy_noSessionRefused(): void
    {
        $this->sentCampaign();
        session_id('');

        self::assertSame(Actions::EXPIRED, $this->copy()['code']);
        self::assertSame([[], []], [$this->drafts->writes, $this->drafts->lockLog]);
    }

    public function test_copy_busyWhileLocked(): void
    {
        $this->sentCampaign();
        $this->drafts->lockedByOther = true;

        self::assertSame(['code' => SendStart::BUSY, 'campaign' => self::copyId(), 'made' => false], $this->copy());
        self::assertSame([], $this->drafts->writes);
    }
}
