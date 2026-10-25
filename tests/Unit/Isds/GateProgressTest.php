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

    private const QUESTIONS = ['state', 'total', 'sent', 'failed', 'skipped', 'uncertain', 'stopped', 'queued', 'until', 'delivered', 'accepted'];

    /** counted of QUESTIONS by the gate, so that a template counts nothing */
    private const DERIVED = ['messages', 'attention', 'checking', 'pending', 'verify', 'stop_by', 'error'];

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
        $_GET = [];
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

            /** its end ran (closing()); null = as a run given up whose close row is gone */
            public ?bool $closed = null;

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

            /** of a run given up the queue tells it (closeWaits()), which is not here: closing() does */
            public function verifiable(): bool
            {
                return ($this->state() === SendRun::ENDED) ? ($this->closed ?? true) : parent::verifiable();
            }
        };
    }

    /** $run given up, its end - the close row in the queue - $closed already */
    private static function closing(SendRun $run, bool $closed): SendRun
    {
        (new ReflectionProperty($run, 'closed'))->setValue($run, $closed);

        return $run;
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

    /**
     * Ten recipients, all but one tried, the sending stopped by a person, delivery followed since an hour ago:
     * 0 delivered, 1 accepted by fiction, 2 refused by the antivirus check, 3 undeliverable (an acceptance
     * it had counts for nothing), 4 only submitted, 5 accepted by a login, 6 refused when sent,
     * 7 skipped (its frozen reason), 8 never tried, 9 uncertain
     * @param array<string, string|array<array-key, mixed>> $props
     */
    private static function watchedRun(array $props = []): SendRun
    {
        $t = self::NOW - 86400;

        return self::sendRun($props + [
            'cursor'     => '10',
            'stop_by'    => Sending::READER,
            'dm_ids'     => ['0' => '501', '1' => '502', '2' => '503', '3' => '504', '4' => '505', '5' => '506'],
            'dm_state'   => ['0' => "4:$t:$t:0", '1' => "6:$t:$t:" . ($t + 1), '2' => "3:$t:0:0", '3' => "8:$t:$t:" . ($t + 2), '4' => "1:$t:0:0", '5' => "5:$t:$t:" . ($t + 3)],
            'failed'     => ['6' => '1233'],
            'uncertain'  => ['9' => 1700000000],
            'watch_from' => (string) (self::NOW - 3600),
            'position'   => ['p' => 'watch', 'i' => 0],
        ], ['', '', '', '', '', '', '', 'duplicate', '', '']);
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
        self::assertSame(['total' => '10', 'sent' => '2', 'failed' => '2', 'skipped' => '3', 'uncertain' => '1', 'stopped' => '0', 'queued' => '2', 'delivered' => '0', 'accepted' => '0'], array_diff_key($answers, array_flip(['state', 'until'])));
        self::assertSame(1, $run->reads, 'eleven answers, one read');
        self::assertSame('', $gate->progress(self::ITEM, 'total'), 'outside the UI');
    }

    public function test_progress_messagesWithoutSkipped(): void
    {
        $run     = self::tenRecipients();
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run]), questions: [...self::QUESTIONS, ...self::DERIVED]);

        self::assertSame(['total' => '10', 'skipped' => '3', 'messages' => '7'], array_intersect_key($answers, array_flip(['total', 'skipped', 'messages'])));
        self::assertSame(1, $run->reads, 'all the answers, one read');
    }

    /** @return array<string, array{\Closure(): SendRun}> */
    public static function runs(): array
    {
        return [
            'sending'          => [static fn(): SendRun => self::tenRecipients()],
            'stopping'         => [static fn(): SendRun => self::tenRecipients(['stop_by' => Sending::READER])],
            'given up'         => [static fn(): SendRun => self::tenRecipients(['state' => SendRun::ENDED])],
            'watching'         => [static fn(): SendRun => self::watchedRun()],
            'following failed' => [static fn(): SendRun => self::watchedRun(['state' => SendRun::ERROR])],
        ];
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('runs')]
    public function test_progress_sumOfMessages(\Closure $run): void
    {
        $a = array_map('intval', self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run()]), questions: ['total', 'sent', 'failed', 'skipped', 'uncertain', 'stopped', 'queued', 'messages', 'attention', 'checking']));

        self::assertSame($a['sent'] + $a['failed'] + $a['uncertain'] + $a['stopped'] + $a['queued'], $a['messages']);
        self::assertSame($a['total'] - $a['skipped'], $a['messages']);
        self::assertSame($a['failed'] + $a['uncertain'], $a['attention'] + $a['checking'], 'each failed or uncertain one is in one of them');
    }

    // the message in flight may still go: the sending finds out itself, no one is to act on it
    public function test_progress_uncertainWhileSendingIsChecking(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]), questions: ['state', 'failed', 'uncertain', 'attention', 'checking']);

        self::assertSame(['state' => 'sending', 'failed' => '2', 'uncertain' => '1', 'attention' => '2', 'checking' => '1'], $answers);
    }

    /**
     * Runs by whether a message of unknown fate is still looked up (SendRun::verifiable()) - with its
     * state of the page, which alone does not tell it
     * @return array<string, array{\Closure(): SendRun, string, bool}> the run, its state, verifiable
     */
    public static function lookups(): array
    {
        return [
            'sending'                     => [static fn(): SendRun => self::tenRecipients(), 'sending', false],
            'stopping'                    => [static fn(): SendRun => self::tenRecipients(['stop_by' => Sending::READER]), 'sending', false],
            'in error'                    => [static fn(): SendRun => self::tenRecipients(['state' => SendRun::ERROR]), 'error', false],
            'looking up in error'         => [static fn(): SendRun => self::watchedRun(['state' => SendRun::ERROR, 'watch_from' => '', 'position' => ['p' => 'look', 'i' => 0]]), 'error', false],
            // the step of look that started following delivery, or Continue repeating it: it may still find the message
            'watching, its look repeated' => [static fn(): SendRun => self::watchedRun(['position' => ['p' => 'look', 'i' => 0]]), 'watching', false],
            'watch_error, look repeated'  => [static fn(): SendRun => self::watchedRun(['state' => SendRun::ERROR, 'position' => ['p' => 'look', 'i' => 0]]), 'watch_error', false],
            'given up, its end queued'    => [static fn(): SendRun => self::closing(self::tenRecipients(['state' => SendRun::ENDED]), false), 'abandoned', false],
            'given up, its end run'       => [static fn(): SendRun => self::closing(self::tenRecipients(['state' => SendRun::ENDED]), true), 'abandoned', true],
            'watching'                    => [static fn(): SendRun => self::watchedRun(), 'watching', true],
            'following failed'            => [static fn(): SendRun => self::watchedRun(['state' => SendRun::ERROR]), 'watch_error', true],
            'done'                        => [static fn(): SendRun => self::watchedRun(['state' => SendRun::DONE]), 'done', true],
            'following given up, closing' => [static fn(): SendRun => self::closing(self::watchedRun(['state' => SendRun::ENDED]), false), 'done', false],
        ];
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('lookups')]
    public function test_progress_attentionOnlyAfterLookup(\Closure $run, string $state, bool $verifiable): void
    {
        $run     = $run();
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run]), questions: ['state', 'failed', 'uncertain', 'attention', 'checking', 'verify']);
        [$failed, $uncertain] = [(int) $answers['failed'], (int) $answers['uncertain']];

        self::assertSame([$state, $verifiable], [$answers['state'], $run->verifiable()], 'the run as described');
        self::assertSame(1, $uncertain, 'one of unknown fate in each');
        self::assertSame($verifiable ? '1' : '', $answers['verify']);
        self::assertSame($verifiable ? [$failed + $uncertain, 0] : [$failed, $uncertain], [(int) $answers['attention'], (int) $answers['checking']]);
    }

    /** @return array<string, array{\Closure(): SendRun, string}> the run, pending */
    public static function deliveries(): array
    {
        return [
            'followed'              => [static fn(): SendRun => self::watchedRun(), '1'],
            'followed, then done'   => [static fn(): SendRun => self::watchedRun(['state' => SendRun::DONE]), '1'],
            // delivered is 0 before: every message sent would pass for waiting for its delivery
            'sending, not followed' => [static fn(): SendRun => self::tenRecipients(), ''],
            'done, never followed'  => [static fn(): SendRun => self::watchedRun(['state' => SendRun::DONE, 'watch_from' => '']), ''],
        ];
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('deliveries')]
    public function test_progress_pendingOnlyWhileDeliveryFollowed(\Closure $run, string $pending): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done')], [self::ITEM => $run()]), questions: ['sent', 'delivered', 'pending']);

        self::assertSame($pending, $answers['pending']);
        if ($pending !== '') {
            self::assertSame(['sent' => '4', 'delivered' => '3'], array_intersect_key($answers, ['sent' => 1, 'delivered' => 1]));
        }
    }

    public function test_progress_derivedEmptyWithoutRun(): void
    {
        $none    = array_fill_keys(self::DERIVED, '');
        $derived = static function (FakeGate $gate): array {
            // a request reads the progress once
            AA::Contentcache()->clear();

            return self::answers($gate, questions: self::DERIVED);
        };

        self::assertSame($none, $derived(self::gate([self::ITEM => self::campaign('draft')])), 'never queued');
        self::assertSame($none, $derived(self::gate([self::ITEM => self::campaign('sending', Sending::OTHER)], [self::ITEM => self::tenRecipients(['stop_by' => Sending::READER])])), 'not a campaign of the page');
        self::assertSame($none, $derived(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients(['dm_ids' => '{"0":'])])), 'a record edited out of shape');
        self::assertNotSame($none, $derived(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()])), 'a run');
    }

    // the record tells who and why apart from the maps: a map edited out of shape leaves them
    public function test_progress_stopByAndErrorOfARecordOutOfShape(): void
    {
        $run     = self::tenRecipients(['dm_ids' => '{"0":', 'stop_by' => Sending::READER, 'state' => SendRun::ERROR, 'error_code' => SendRun::REFUSED]);
        $answers = self::answers(self::gate([self::ITEM => self::campaign('error')], [self::ITEM => $run]), questions: ['state', 'total', 'messages', 'stop_by', 'error']);

        self::assertSame(['state' => 'error', 'total' => '', 'messages' => '', 'stop_by' => Sending::READER, 'error' => SendRun::REFUSED], $answers);
    }

    public function test_progress_stopByThroughPermUsername(): void
    {
        $perms                         = FakePerms::install([Sending::SET => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]], [Sending::READER => [Sending::READERS, Sending::SET]]);
        $perms->names[Sending::READER] = 'Jana <b>Nová</b> & "spol."';
        $stopped                       = static function (string $uid): string {
            // a request reads the progress once
            AA::Contentcache()->clear();

            return self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients(($uid === '') ? [] : ['stop_by' => $uid])]), questions: ['stop_by'])['stop_by'];
        };

        self::assertSame('Jana &lt;b&gt;Nová&lt;/b&gt; &amp; &quot;spol.&quot;', $stopped(Sending::READER));
        self::assertSame('', $stopped(''), 'no one stopped it');
        // a login AA no longer knows shows as its id, as everywhere in AA
        self::assertSame('&lt;gone&gt;', $stopped('<gone>'));
    }

    /** @return array<string, array{array<string, string|array<array-key, mixed>>, string}> props of the run, error */
    public static function failures(): array
    {
        // literals: a data provider runs before SendRun can be loaded
        return [
            'refused in a run'            => [['state' => 'error', 'error_code' => 'ISDS_REFUSED', 'error_detail' => 'status:1233'], 'ISDS_REFUSED'],
            'the account changed'         => [['state' => 'error', 'error_code' => 'ACCOUNT_CHANGED'], 'ACCOUNT_CHANGED'],
            'waiting for the lookup'      => [['retry_code' => 'ISDS_LOOKUP'], 'ISDS_LOOKUP'],
            'waiting for a round'         => [['retry_code' => 'ISDS_WATCH', 'watch_from' => (string) (self::NOW - 3600)], 'ISDS_WATCH'],
            'waiting out ISDS'            => [['retry_code' => 'ISDS_UNAVAILABLE'], 'ISDS_UNAVAILABLE'],
            'an internal error'           => [['state' => 'error', 'error_code' => 'INTERNAL', 'error_path' => 'RuntimeException'], 'INTERNAL'],
            'no failure'                  => [[], ''],
            'retrying the end'            => [['retry_code' => 'FINAL_CHECK'], 'FINAL_CHECK'],
            'its end failed for good'     => [['state' => 'error', 'error_code' => 'FINAL_CHECK'], 'FINAL_CHECK'],
            'a step that kept dying'      => [['state' => 'error', 'error_code' => 'STEP_DIED'], 'STEP_DIED'],
            'the frozen part changed'     => [['state' => 'error', 'error_code' => 'CONTENT_CHANGED', 'error_detail' => 'rcpt'], 'CONTENT_CHANGED'],
            'a code AA has no text of'    => [['state' => 'error', 'error_code' => 'NO_SUCH_CODE'], ''],
            'an action code'              => [['state' => 'error', 'error_code' => 'SAVED'], ''],
            // retry_code is shown only while the run is active
            'retry left in a run in error' => [['state' => 'error', 'retry_code' => 'ISDS_LOOKUP'], ''],
            'a code edited out of shape'  => [['state' => 'error', 'error_code' => '<b>'], ''],
            // a run that ended keeps its last code: it no longer holds
            'given up after a refusal'    => [['state' => 'stopped', 'error_code' => 'ISDS_REFUSED'], ''],
            'done after an error'         => [['state' => 'done', 'error_code' => 'ACCOUNT_CHANGED'], ''],
            'following failed'            => [['state' => 'error', 'error_code' => 'ISDS_UNAVAILABLE', 'watch_from' => (string) (self::NOW - 3600)], 'ISDS_UNAVAILABLE'],
        ];
    }

    /** @param array<string, string|array<array-key, mixed>> $props */
    #[DataProvider('failures')]
    public function test_progress_errorOnlyKnownCodes(array $props, string $error): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients($props)]), questions: ['error']);

        self::assertSame($error, $answers['error']);
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

    /** @return array<string, array{array<string, string|array<array-key, mixed>>, string}> props of the run, state */
    public static function watched(): array
    {
        return [
            'waiting for its round'         => [['retry_code' => 'ISDS_WATCH'], 'watching'],
            // ISDS down is waited out, not failed
            'waiting out ISDS'              => [['retry_code' => 'ISDS_MAINTENANCE'], 'watching'],
            // the step of look that started it: the record decides, not the position
            'started in the step of look'   => [['position' => ['p' => 'look', 'i' => 0]], 'watching'],
            'a position of watch, no start' => [['watch_from' => ''], 'sending'],
        ];
    }

    /** @param array<string, string|array<array-key, mixed>> $props */
    #[DataProvider('watched')]
    public function test_progress_watching(array $props, string $state): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done')], [self::ITEM => self::watchedRun($props)]));

        self::assertSame($state, $answers['state']);
    }

    public function test_progress_watchError(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done')], [self::ITEM => self::watchedRun(['state' => SendRun::ERROR, 'error_code' => SendRun::ACCOUNT_CHANGED])]));

        self::assertSame(['watch_error', '10', '1'], [$answers['state'], $answers['total'], $answers['stopped']]);
    }

    // Stop of a following in error gives it up: all was sent, the sending is over
    public function test_progress_abandonedWatchIsDone(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done')], [self::ITEM => self::watchedRun(['state' => SendRun::ENDED, 'progress' => ['note' => SendRun::ABANDONED]])]));

        self::assertSame(['done', '3', '2'], [$answers['state'], $answers['delivered'], $answers['accepted']]);
    }

    /** @return array<string, array{array<string, string|array<array-key, mixed>>, string}> props of the run, state */
    public static function sendingFailed(): array
    {
        return [
            'sending in error'     => [['state' => 'error'], 'error'],
            'looking up in error'  => [['state' => 'error', 'position' => ['p' => 'look', 'i' => 0]], 'error'],
            'sending given up'     => [['state' => 'stopped'], 'abandoned'],
            'looking up, given up' => [['state' => 'stopped', 'position' => ['p' => 'look', 'i' => 0]], 'abandoned'],
        ];
    }

    /** @param array<string, string|array<array-key, mixed>> $props */
    #[DataProvider('sendingFailed')]
    public function test_progress_sendingErrorStaysError(array $props, string $state): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('error')], [self::ITEM => self::watchedRun($props + ['watch_from' => ''])]));

        self::assertSame($state, $answers['state']);
    }

    // statuses 3 and 8 are failed, neither delivered nor accepted; status 1 is not delivered yet; the counts still add up to total
    public function test_progress_deliveredAndAccepted(): void
    {
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done')], [self::ITEM => self::watchedRun()]));

        self::assertSame(
            ['state' => 'watching', 'total' => '10', 'sent' => '4', 'failed' => '3', 'skipped' => '1', 'uncertain' => '1', 'stopped' => '1', 'queued' => '0', 'until' => '', 'delivered' => '3', 'accepted' => '2'],
            $answers,
        );
    }

    /** @return array<string, array{array<string, int|string>, string, bool}> position, watch_from, waits */
    public static function watchPauses(): array
    {
        $now = self::NOW;

        return [
            'the next round'                 => [['p' => 'watch', 'i' => 0, 'u' => $now + 300], (string) ($now - 3600), false],
            'the step of look that began it' => [['p' => 'look', 'i' => 0, 'u' => $now + 300], (string) ($now - 3600), false],
            'looking up, not watching yet'   => [['p' => 'look', 'i' => 0, 'u' => $now + 300], '', true],
        ];
    }

    /** @param array<string, int|string> $position */
    #[DataProvider('watchPauses')]
    public function test_progress_untilEmptyWhileWatching(array $position, string $watchFrom, bool $waits): void
    {
        $run     = self::watchedRun(['position' => $position, 'watch_from' => $watchFrom, 'retry_code' => SendRun::WATCHING]);
        $answers = self::answers(self::gate([self::ITEM => self::campaign('done')], [self::ITEM => $run]));

        self::assertSame($waits ? (string) $position['u'] : '', $answers['until']);
    }

    // ------------------------------------------------------------ messages

    /**
     * What the UI of the page of $campaign (isds_campaign of its URL) gets of each group
     * @param list<string> $groups
     * @return array<string, string> group => answer
     */
    private static function messagesOf(FakeGate $gate, array $groups = ['problem', 'checking', 'wait', 'sent', 'ok', 'stopped', 'skip'], string $what = '', string $campaign = self::ITEM): array
    {
        $_GET = ['isds_campaign' => $campaign];
        $got  = null;
        $gate->page(self::settings(), 'UI', 'refused', static function () use ($gate, $groups, $what, &$got): string {
            $got = array_combine($groups, array_map(static fn(string $group): string => $gate->messages($group, $what), $groups));

            return '';
        });
        self::assertIsArray($got, 'the gate opens');

        return $got;
    }

    public function test_messages_campaignWithoutMessagesEmpty(): void
    {
        $gate        = self::gate([self::ITEM => self::campaign('draft')]);
        $gate->tests = [self::ITEM => ['te00000000000000000000000000000b', 'te00000000000000000000000000000a']];
        $groups      = ['problem', 'checking', 'wait', 'sent', 'ok', 'stopped', 'skip', 'recent'];

        self::assertSame(array_fill_keys($groups, ''), self::messagesOf($gate, $groups));
        self::assertSame(array_fill_keys($groups, ''), self::messagesOf($gate, $groups, 'count'), 'no count of 0 - as progress');
        self::assertSame(['test' => 'te00000000000000000000000000000b-te00000000000000000000000000000a'], self::messagesOf($gate, ['test']), 'tests come before a sending');
        self::assertSame('', $gate->messages('test'), 'outside the UI');
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('runs')]
    public function test_messages_groupCountsEqualProgressFigures(\Closure $run): void
    {
        $gate     = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run()]);
        $answers  = self::answers($gate, questions: ['sent', 'skipped', 'stopped', 'queued', 'delivered', 'attention', 'checking', 'pending']);
        $p        = array_map('intval', $answers);
        $counts   = array_map('intval', self::messagesOf($gate, what: 'count'));
        $followed = $answers['pending'] !== '';

        self::assertSame([
            'problem'  => $p['attention'],
            'checking' => $p['checking'],
            'wait'     => $p['queued'] + $p['pending'],
            'sent'     => $followed ? 0 : $p['sent'] - $p['delivered'],
            'ok'       => $p['delivered'],
            'stopped'  => $p['stopped'],
            'skip'     => $p['skipped'],
        ], $counts);
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('runs')]
    public function test_messages_groupsDisjointAndComplete(\Closure $run): void
    {
        $listed = array_merge(...array_map(static fn(string $ids): array => array_filter(explode('-', $ids)), array_values(self::messagesOf(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run()])))));

        self::assertSame(['m0', 'm1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8', 'm9'], array_values(array_unique(self::sorted($listed))));
        self::assertCount(10, $listed, 'each once');
    }

    // the button a template shows by resend and the copy to one recipient agree: one rule, of the same reading
    /** @param \Closure(): SendRun $run */
    #[DataProvider('runs')]
    public function test_messages_resendIsWhatCloneOneTakes(\Closure $run): void
    {
        $sending = $run();
        $listed  = self::messagesOf(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $sending]), ['resend'])['resend'];

        $takes = array_filter(array_map(static fn(int $k): string => "m$k", range(0, 9)), static fn(string $msg): bool => ($sending->resendSource($msg) ?? '') !== '');
        self::assertNotSame([], $takes, 'each run has some');
        self::assertSame(implode('-', $takes), $listed);
    }

    // failed after sending (2, 3), refused when sent (6) and stopped (8); never the uncertain 9
    public function test_messages_resendFailedAndStopped(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('done')], [self::ITEM => self::watchedRun()]);

        self::assertSame(['problem' => 'm2-m3-m6-m9', 'stopped' => 'm8', 'resend' => 'm2-m3-m6-m8'], self::messagesOf($gate, ['problem', 'stopped', 'resend']));
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    // the frozen order - by the name of the recipient - in each group; the run asks for nothing else
    public function test_messages_inTheFrozenOrder(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('done')], [self::ITEM => self::watchedRun()]);

        self::assertSame(
            ['problem' => 'm2-m3-m6-m9', 'checking' => '', 'wait' => 'm4', 'sent' => '', 'ok' => 'm0-m1-m5', 'stopped' => 'm8', 'skip' => 'm7', 'recent' => 'm9-m8-m7-m6-m5-m4'],
            self::messagesOf($gate, ['problem', 'checking', 'wait', 'sent', 'ok', 'stopped', 'skip', 'recent']),
        );
    }

    // the cursor at 5, 5 in flight: the one being sent first - a person wants to see it
    public function test_messages_recentLastFirst(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]);

        self::assertSame(['recent' => 'm5-m4-m3-m2-m1-m0'], self::messagesOf($gate, ['recent']));
    }

    // the delivery not followed yet: the messages sent wait for nothing the page can tell - as pending ''
    public function test_messages_sentWhileDeliveryNotFollowed(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]);

        self::assertSame(['wait' => 'm8-m9', 'sent' => 'm0-m2', 'ok' => ''], self::messagesOf($gate, ['wait', 'sent', 'ok']));
    }

    // a page shows them beside the one list it pages
    public function test_messages_recentAndTestsOnEveryPage(): void
    {
        $gate        = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]);
        $gate->tests = [self::ITEM => ['te00000000000000000000000000000b', 'te00000000000000000000000000000a']];
        $_GET        = ['isds_campaign' => self::ITEM, 'isds_page' => '2'];
        $got         = null;
        $gate->page(self::settings(), 'UI', 'refused', static function () use ($gate, &$got): string {
            $got = [$gate->messages('recent'), $gate->messages('test'), $gate->messages('problem'), $gate->messages('test', 'count')];

            return '';
        });

        self::assertSame(['m5-m4-m3-m2-m1-m0', 'te00000000000000000000000000000b-te00000000000000000000000000000a', '', '2'], $got);
    }

    // the figures and the lists of a page come of one reading of the run, so they agree
    public function test_messages_progressAndListsReadTheRunOnce(): void
    {
        $run  = self::watchedRun();
        $gate = self::gate([self::ITEM => self::campaign('done')], [self::ITEM => $run]);
        $_GET = ['isds_campaign' => self::ITEM];
        $gate->page(self::settings(), 'UI', 'refused', static function () use ($gate): string {
            return $gate->progress(self::ITEM, 'sent') . $gate->messages('ok') . $gate->messages('wait', 'count');
        });

        self::assertSame(1, $run->reads);
    }

    /**
     * @param array<string, array{slice: string, active: bool, filter: string, state: string}> $campaigns
     * @param ?array<string, string>                                                           $run
     */
    #[DataProvider('notACampaign')]
    public function test_messages_onlyOfCampaignOfPage(array $campaigns, ?array $run, string $campaign): void
    {
        $gate        = self::gate($campaigns, is_null($run) ? [] : [self::ITEM => self::tenRecipients($run)]);
        $gate->tests = [self::ITEM => ['te00000000000000000000000000000b']];
        $groups      = ['problem', 'wait', 'skip', 'recent'];
        // a run of another binding: its tests are searched for in the message slice of the page
        $ofPage      = ($campaigns[$campaign]['slice'] ?? '') === Sending::CAMPAIGN;

        self::assertSame(array_fill_keys($groups, '') + ['test' => $ofPage ? 'te00000000000000000000000000000b' : ''], self::messagesOf($gate, [...$groups, 'test'], campaign: $campaign));
        self::assertSame(array_fill_keys($groups, '') + ['test' => $ofPage ? '1' : ''], self::messagesOf($gate, [...$groups, 'test'], 'count', $campaign));
    }

    public function test_messages_noCampaignInTheUrl(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]);
        $_GET = [];
        $got  = null;
        $gate->page(self::settings(), 'UI', 'refused', static function () use ($gate, &$got): string {
            $got = [$gate->messages('problem'), $gate->messages('skip', 'count')];

            return '';
        });

        self::assertSame(['', ''], $got);
    }

    /** @param \Closure(): SendRun $run */
    #[DataProvider('lookups')]
    public function test_messages_uncertainIsProblemOnlyAfterLookup(\Closure $run, string $state, bool $verifiable): void
    {
        $run    = $run();
        $groups = self::messagesOf(self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run]), ['problem', 'checking']);
        // the one of unknown fate: 5 in flight, or 9
        $uncertain = in_array('m5', explode('-', $groups['problem'] . '-' . $groups['checking']), true) ? 'm5' : 'm9';

        self::assertSame($verifiable, in_array($uncertain, explode('-', $groups['problem']), true), "$state: $uncertain");
        self::assertSame(!$verifiable, $groups['checking'] === $uncertain);
    }

    public function test_messages_pagesOfFifty(): void
    {
        $reasons = array_fill(0, 120, '');
        $run     = self::sendRun(['cursor' => '0', 'stop_by' => Sending::READER], $reasons);
        $gate    = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => $run]);
        $page    = static function (string $page) use ($gate): string {
            $_GET = ['isds_campaign' => self::ITEM, 'isds_page' => $page];
            $got  = '';
            $gate->page(self::settings(), 'UI', 'refused', static function () use ($gate, &$got): string {
                $got = $gate->messages('stopped') . '|' . $gate->messages('stopped', 'count');

                return '';
            });

            return $got;
        };

        self::assertSame(implode('-', array_map(static fn(int $k): string => "m$k", range(100, 119))) . '|120', $page('3'));
        self::assertSame(1, $run->reads, 'the frozen recipients read once');
    }

    public function test_messages_unknownGroupOrQuestion(): void
    {
        $gate = self::gate([self::ITEM => self::campaign('sending')], [self::ITEM => self::tenRecipients()]);

        self::assertSame(['all' => '', 'Problem' => '', '' => ''], self::messagesOf($gate, ['all', 'Problem', '']));
        self::assertSame(['problem' => ''], self::messagesOf($gate, ['problem'], 'ids'));
    }
}
