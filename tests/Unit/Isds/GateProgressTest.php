<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\SendRun;
use AA\Isds\Snapshot;
use AA\Later\BatchFailed;
use AA_Credentials;
use AA_Perm;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Isds\Fixtures\FakeGate;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;

// data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/**
 * {datovkabulk:progress}: how far the sending of a campaign of the page got, from the record of its
 * run and its frozen recipients only - inside the gate, for a campaign of the page's slice.
 */
final class GateProgressTest extends TestCase
{
    private const ITEM   = 'ca00000000000000000000000000000b';   // a campaign - an item of the campaign slice
    private const LETTER = 'be00000000000000000000000000000d';   // a message - an item of the message slice

    private const QUESTIONS = ['state', 'total', 'sent', 'failed', 'skipped', 'uncertain', 'stopped', 'queued', 'until'];

    /** the clock of the runs here: a pause is judged at this time, however slow the machine */
    public const NOW = 1800000000;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/fixtures/FakeSliceRules.php';
        require_once __DIR__ . '/fixtures/FakePerms.php';
        require_once __DIR__ . '/fixtures/FakeGate.php';
        require_once __DIR__ . '/fixtures/FakeRecipients.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                       // AA::Pagecache()
        }
    }

    /** the office: readers in a set the campaign slice grants the editor's role */
    protected function setUp(): void
    {
        FakePerms::install([Sending::SET => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]], [Sending::READER => [Sending::READERS, Sending::SET]]);
    }

    /** what a test changed of the request stays in it */
    protected function tearDown(): void
    {
        FakeSliceRules::forget();
        FakePerms::uninstall();
        Closure::bind(function (): void {
            foreach (get_object_vars($this) as $name => $value) {
                if (is_array($value)) {
                    $this->$name = [];
                }
            }
        }, AA_Credentials::singleton(), AA_Credentials::class)();
        (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
        AA::Contentcache()->clear();
    }

    /**
     * The page of SITE with its campaigns and their runs
     * @param array<string, array{slice: string, active: bool, filter: string, state: string}> $campaigns
     * @param array<string, SendRun>                                                           $runs
     */
    private static function gate(array $campaigns, array $runs = []): FakeGate
    {
        $rules                             = FakeSliceRules::ready(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE)->withSource(Sending::SITE, Sending::SOURCE);
        $rules->slices[Sending::CAMPAIGN]  = FakeSliceRules::sliceOf(Sending::CAMPAIGN, true, ['reading_password' => AA_Credentials::encrypt('gate-campaign')]);
        $rules->slices[Sending::MESSAGE]   = FakeSliceRules::sliceOf(Sending::MESSAGE, false, ['reading_password' => AA_Credentials::encrypt('gate-message')]);
        $gate                              = new FakeGate(Sending::SITE, $rules, Sending::bound(), FakePerms::login(Sending::READER));
        $gate->campaigns                   = $campaigns;
        $gate->runs                        = $runs;

        return $gate;
    }

    /** @return array{slice: string, active: bool, filter: string, state: string} an item of the campaign slice in $state */
    private static function campaign(string $state, string $slice = Sending::CAMPAIGN, bool $active = true): array
    {
        return ['slice' => $slice, 'active' => $active, 'filter' => '', 'state' => $state];
    }

    /**
     * A run read from its record, with the frozen recipients - each skipped for its reason, '' = not
     * skipped - or the failure of reading them
     * @param array<string, string|array<array-key, mixed>> $props own properties; an array is the JSON of a map or the position
     * @param list<string>                                  $reasons
     */
    private static function sendRun(array $props, array $reasons, ?\Throwable $unreadable = null): SendRun
    {
        $rows = array_map(static fn(int $k, string $reason): array => array_combine(Snapshot::ROW, ["m$k", "s$k", "box$k", "Town $k", $reason]), array_keys($reasons), $reasons);
        $props = array_map(static fn(string|array $v): string => is_array($v) ? json_encode($v, JSON_FORCE_OBJECT) : $v, $props + ['camp_slice' => Sending::CAMPAIGN, 'msg_slice' => Sending::MESSAGE, 'state' => SendRun::SENDING]);

        return new class ($props, $rows, $unreadable) extends SendRun {
            public int $reads = 0;

            /** @param list<array<string, string>> $frozen */
            public function __construct(array $props, private array $frozen, private ?\Throwable $unreadable)
            {
                parent::__construct($props);
            }

            public function recipients(): array
            {
                ++$this->reads;

                return $this->unreadable ? throw $this->unreadable : $this->frozen;
            }

            protected static function now(): int
            {
                return GateProgressTest::NOW;
            }
        };
    }

    /**
     * Ten recipients, the cursor at 5; each counts by the first map that says most, wherever the cursor is:
     * 0 sent, 1 skipped (its frozen reason, behind the cursor), 2 sent (looked up after uncertain),
     * 3 refused (uncertain at first), 4 its box inactive, 5 in flight, 6 refused past the cursor,
     * 7 skipped (its frozen reason, past the cursor), 8 and 9 never tried
     * @param array<string, string|array<array-key, mixed>> $props
     */
    private static function tenRecipients(array $props = []): SendRun
    {
        return self::sendRun($props + [
            'cursor'      => '5',
            'dm_ids'      => ['0' => '501', '2' => '503'],
            'uncertain'   => ['2' => 1700000000, '3' => 1700000000],
            'failed'      => ['3' => '1233', '6' => '1233'],
            'skips'       => ['4' => 'inactive'],
            'inflight'    => '5',
            'inflight_at' => '1700000100',
            'position'    => ['p' => 'send', 'i' => 5],
        ], ['', 'missing', '', '', '', '', '', 'duplicate', '', '']);
    }

    private static function settings(): string
    {
        return (string) json_encode(['campaign_slice' => Sending::CAMPAIGN, 'message_slice' => Sending::MESSAGE, 'source_slice' => Sending::SOURCE, 'types' => ['mesto' => 'Cities'], 'default_types' => ['mesto'], 'member_field' => Sending::MEMBER]);
    }

    /**
     * What the UI of the page gets for $campaign
     * @param list<string> $questions
     * @return array<string, string> question => answer
     */
    private static function answers(FakeGate $gate, string $campaign = self::ITEM, array $questions = self::QUESTIONS): array
    {
        $got = null;
        $gate->page(self::settings(), 'UI', 'refused', static function () use ($gate, $campaign, $questions, &$got): string {
            $got = array_combine($questions, array_map(static fn(string $what): string => $gate->progress($campaign, $what), $questions));

            return '';
        });
        self::assertIsArray($got, 'the gate opens');

        return $got;
    }

    public function test_progress_whileSending(): void
    {
        $run  = self::tenRecipients();
        $gate = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run]);

        $answers = self::answers($gate, strtoupper(self::ITEM));

        self::assertSame(['state' => 'sending', 'until' => ''], array_intersect_key($answers, array_flip(['state', 'until'])));
        self::assertSame(['total' => '10', 'sent' => '2', 'failed' => '2', 'skipped' => '3', 'uncertain' => '1', 'stopped' => '0', 'queued' => '2'], array_diff_key($answers, array_flip(['state', 'until'])));
        self::assertSame(1, $run->reads, 'nine answers, one read');
        self::assertSame('', $gate->progress(self::ITEM, 'total'), 'outside the UI');
    }

    /** @return array<string, array{array<string, string>, string, string, string}> props of the run, state, stopped, queued */
    public static function neverTried(): array
    {
        return [
            'sending'                 => [['state' => 'sending'], 'sending', '0', '2'],
            'in error, till Continue' => [['state' => 'error'], 'error', '0', '2'],
            'stopping'                => [['state' => 'sending', 'stop_by' => Sending::READER], 'sending', '2', '0'],
            'stopped in error'        => [['state' => 'error', 'stop_by' => Sending::READER], 'error', '2', '0'],
            // 5, in flight when given up, stays uncertain
            'given up'                => [['state' => 'stopped'], 'abandoned', '2', '0'],
            // a run that ends has nothing in flight: 5 was never tried
            'stopped, done'           => [['state' => 'done', 'stop_by' => Sending::READER, 'inflight' => '', 'inflight_at' => ''], 'done', '3', '0'],
            // the record edited by hand: a finished run sends nothing more, whatever it left
            'done, some never tried'  => [['state' => 'done', 'inflight' => '', 'inflight_at' => ''], 'done', '3', '0'],
        ];
    }

    /** @param array<string, string> $props */
    #[DataProvider('neverTried')]
    public function test_progress_neverTriedQueuedTillAStopOrTheEnd(array $props, string $state, string $stopped, string $queued): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients($props)]));

        self::assertSame([$state, $stopped, $queued], [$answers['state'], $answers['stopped'], $answers['queued']]);
        self::assertSame('3', $answers['skipped'], 'a recipient skipped by its frozen reason is never stopped, past the cursor too');
        self::assertSame(10, array_sum(array_map('intval', array_intersect_key($answers, array_flip(['sent', 'failed', 'skipped', 'uncertain', 'stopped', 'queued'])))), 'they add up to total');
    }

    /** @return array<string, array{string, array<string, int|string>, bool}> state, position, waits */
    public static function pauses(): array
    {
        $now = self::NOW;

        return [
            'waiting'                 => ['sending', ['p' => 'send', 'i' => 5, 'w' => 1, 'u' => $now + 300, 's' => $now - 30], true],
            'looking up, waiting'     => ['sending', ['p' => 'look', 'i' => 0, 'u' => $now + 900], true],
            'the pause is over'       => ['sending', ['p' => 'send', 'i' => 5, 'w' => 1, 'u' => $now - 1], false],
            'no pause'                => ['sending', ['p' => 'send', 'i' => 5], false],
            // as the run reads it: a pause that long was edited by hand, nothing waits for it
            'too far ahead'           => ['sending', ['p' => 'send', 'i' => 5, 'u' => $now + 86400], false],
            'in error, left in place' => ['error', ['p' => 'send', 'i' => 5, 'u' => $now + 300], false],
        ];
    }

    /** @param array<string, int|string> $position */
    #[DataProvider('pauses')]
    public function test_progress_untilWhileAPauseWaits(string $state, array $position, bool $waits): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients(['state' => $state, 'position' => $position])]));

        self::assertSame($waits ? (string) $position['u'] : '', $answers['until']);
    }

    // a campaign never queued: its own state, nothing counted - '' is no count of 0
    public function test_progress_beforeAnyRun(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('draft')]));

        self::assertSame(['state' => 'draft'] + array_fill_keys(array_slice(self::QUESTIONS, 1), ''), $answers);
    }

    /** @return array<string, array{string}> */
    public static function unknownStates(): array
    {
        return [
            'edited by hand'            => ['paused'],
            'read without its password' => ['Error: Missing Reading Password'],
        ];
    }

    #[DataProvider('unknownStates')]
    public function test_progress_unknownStateOfTheCampaignIsNone(string $state): void
    {
        self::assertSame('', self::answers(self::gate([self::ITEM => self::campaign($state)]))['state']);
    }

    // the counts still come from the record, the state only from the list of the run's states
    public function test_progress_unknownStateOfTheRunIsNone(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients(['state' => 'paused'])]));

        self::assertSame(['', '10'], [$answers['state'], $answers['total']]);
    }

    // a campaign moved to a bin or expired keeps the record of what was sent
    public function test_progress_campaignOutOfTheActiveBin(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done', active: false)], [self::ITEM => self::tenRecipients(['state' => SendRun::DONE])]));

        self::assertSame(['done', '10'], [$answers['state'], $answers['total']]);
    }

    /** @return array<string, array{array<string, array{slice: string, active: bool, filter: string, state: string}>, ?array<string, string>, string}> campaigns, props of a run of ITEM (null = none), the id asked */
    public static function notACampaign(): array
    {
        return [
            'not an id'                              => [[self::ITEM => self::campaign('draft')], null, '12" onclick="x'],
            'no such item'                           => [[], [], self::ITEM],
            'an item of messages'                    => [[self::LETTER => self::campaign('sent', Sending::MESSAGE)], null, self::LETTER],
            'a campaign of another page, never sent' => [[self::ITEM => self::campaign('draft', Sending::OTHER)], null, self::ITEM],
            'a campaign of another page'             => [[self::ITEM => self::campaign('sending', Sending::OTHER)], ['camp_slice' => Sending::OTHER], self::ITEM],
            'a run from another campaign slice'      => [[self::ITEM => self::campaign('sending')], ['camp_slice' => Sending::OTHER], self::ITEM],
            // another binding with the same campaign slice
            'a run into another message slice'       => [[self::ITEM => self::campaign('sending')], ['msg_slice' => Sending::OTHER], self::ITEM],
        ];
    }

    /**
     * @param array<string, array{slice: string, active: bool, filter: string, state: string}> $campaigns
     * @param ?array<string, string>                                                           $run
     */
    #[DataProvider('notACampaign')]
    public function test_progress_notACampaignOfThePage(array $campaigns, ?array $run, string $campaign): void
    {
        $runs = is_null($run) ? [] : [self::ITEM => self::tenRecipients($run)];

        self::assertSame(array_fill_keys(self::QUESTIONS, ''), self::answers(self::gate($campaigns, $runs), $campaign));
    }

    public function test_progress_unknownQuestion(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]);

        self::assertSame(['all' => '', '' => '', 'Total' => ''], self::answers($gate, questions: ['all', '', 'Total']));
    }

    /** @return array<string, array{\Closure(): SendRun}> */
    public static function unreadable(): array
    {
        return [
            'a map'                 => [static fn(): SendRun => self::tenRecipients(['dm_ids' => '{"0":'])],
            'the position'          => [static fn(): SendRun => self::tenRecipients(['position' => '{"p":'])],
            'the frozen recipients' => [static fn(): SendRun => self::sendRun(['cursor' => '0'], [''], new BatchFailed(Snapshot::CHANGED, 'snapshot', 'rcpt'))],
        ];
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('unreadable')]
    public function test_progress_recordChangedOutsideAaCountsNothing(\Closure $run): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run()]));

        self::assertSame(['state' => 'sending'] + array_fill_keys(array_slice(self::QUESTIONS, 1), ''), $answers);
    }
}
