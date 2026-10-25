<?php

declare(strict_types=1);

namespace AA\Isds;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\BatchStates;
use AA\Later\BatchStepResult;
use AA\Later\Toexecute;
use AA\Util\Secret;
use AA_Credentials;
use AA_Log;
use AA_Property;

/**
 * The run of a mass sending, one per campaign for good: its record says whom, from where and what -
 * the snapshot frozen when it was queued - and how far it got. The items of the campaign and its
 * messages it only writes, for people to see: an item changed by hand sends nothing else.
 * Phases of the position {p, i}: prep makes a message item for each frozen recipient; send sends
 * them one at a time, the account checked before ISDS is asked anything; look finds out what became
 * of the messages of unknown fate (Lookup) before the sending ends. A stop (stop()) turns a run in
 * prep or send to the phase stop, which cancels what was never tried - with no account and no ISDS,
 * so it works while they fail - and goes on to look: what was sent is still looked up. A look keeps
 * in l how far it read the list, so that a long one is read over several steps.
 * Once look is over, a sending that sent messages follows their delivery (watch, StatusSync): the
 * campaign shows its end at once, the run reads the state changes of ISDS each hour into its record
 * (dm_state) and the message items show them. It ends in the morning when every message is in a final
 * status, or after StatusSync::WATCH_DAYS. What the changes never tell it reads from the list of sent
 * messages (StatusSync::due()). Its position {p, n} counts the steps, z the span of a call shortened by
 * an answer too large, d how far the day being read whole is read, l how far the list is read.
 * A pause waits for ISDS without spending the run's retries: w pauses in a row, u the time to wait
 * till, s the start of the first one (SendPace).
 */
class SendRun extends BatchRun
{
    /** BatchFailed reason: datovka_conf.php no longer binds the account the run was queued with, or its login now belongs to another box */
    public const ACCOUNT_CHANGED = 'ACCOUNT_CHANGED';
    /** BatchFailed reason: ISDS refused to say whose the login is, or refused SendPace::REFUSALS messages in a row with
     *  one status (SendPace::breaker()); the detail is the status */
    public const REFUSED = 'ISDS_REFUSED';
    /** BatchFailed reason: the campaign or the message slice breaks a rule of SliceRules since the run was queued */
    public const SETTINGS = 'SETTINGS';
    /** BatchFailed reason: memory_limit is too low for the files of a message and cannot be raised; the detail is the MB needed */
    public const MEMORY = 'MEMORY';
    /** BatchStepResult::$retry: ISDS is being maintained, the run waits till it ends */
    public const MAINTENANCE = 'ISDS_MAINTENANCE';
    /** warning: messages sent without the state of their box - ISDS refused the check or did not say it */
    public const UNCHECKED = 'CHECKBOX_REFUSED';
    /** BatchStepResult::$retry: messages of unknown fate are not listed yet, the run looks again when they may be */
    public const LOOKING = 'ISDS_LOOKUP';
    /** BatchStepResult::$retry: following delivery waits for the next round */
    public const WATCHING = 'ISDS_WATCH';
    /** stop(), resumeFor(): another request holds the lock of the campaign */
    public const BUSY = 'BUSY';
    /** stop(): every message has been tried, nothing is left to stop */
    public const ALL_SENT = 'ALL_SENT';
    /** stop() of a run in error that cannot be stopped any more: only giving it up (abandonable()) would end it */
    public const CONFIRM_ABANDON = 'CONFIRM_ABANDON';
    /** stop() asked to give a run up, and it did: its note */
    public const ABANDONED = 'ABANDONED';
    /** verify(): the message is failed as verified - now, or before */
    public const VERIFIED = 'VERIFIED';
    /** verify(): the fate of the message is known, or it was never tried */
    public const NOT_UNCERTAIN = 'NOT_UNCERTAIN';
    /** verify(): no recipient of the run has that message */
    public const UNKNOWN_MESSAGE = 'UNKNOWN_MESSAGE';
    /** the states of a run: queued or working, stopped by an error, done, given up (BatchRun::end()) */
    public const SENDING = 'sending';
    public const ERROR   = 'error';
    public const DONE    = 'done';
    public const ENDED   = 'stopped';

    /** the error BatchRun gives a failure it does not know */
    private const INTERNAL = 'INTERNAL';

    private const PREP = 'prep';
    private const SEND = 'send';
    private const STOP = 'stop';
    private const LOOK = 'look';
    private const WATCH = 'watch';
    private const PHASES = [self::PREP, self::SEND, self::STOP, self::LOOK, self::WATCH];

    /** the run's own properties, each a text row of the record */
    private const OWN = [
        'site', 'env', 'account', 'kind', 'acct_id', 'sender_box', 'camp_slice', 'msg_slice', 'camp_short',
        'snapshot', 'manifest', 'cursor', 'inflight', 'inflight_at', 'dm_ids', 'uncertain', 'failed', 'skips',
        'streak', 'camp_state', 'owner_at', 'stop_by', 'watch_from', 'watch_last', 'watch_day', 'watch_rec',
        'dm_state', 'dm_dirty',
    ];

    /** message items in one transaction: a commit each would cost a sync of the disk each */
    private const CHUNK = 50;

    /** the key of Account::fingerprint() in the record - a fingerprint of another purpose does not fit it */
    private const FINGERPRINT = 'isds-send';

    /** seconds between checks of the box the login belongs to, when its fingerprint did not change */
    private const OWNER_EVERY = 3600;
    /** the same while following delivery: nothing is sent, the fingerprint alone tells a new sender, and
     *  ISDS asks for no more questions than needed */
    private const OWNER_WATCHING = 86400;

    /** seconds a step must have left to start a message; the message may take longer, the lease covers it (overrun()) */
    private const MIN_SEND = 3;
    /** the position of a pause: w pauses in a row, u the time to wait till, s the start of the first, e the steps run before u */
    private const PAUSE_KEYS = ['w', 'u', 's', 'e'];
    /** the position of a reading of the list of sent messages a step did not finish: of look (Lookup::$scan),
     *  of watch (StatusSync::listCursor()) */
    private const READING = 'l';
    /** the position of watch: the span of a call after ISDS answered one too large, till the round ends */
    private const SPAN = 'z';
    /** the position of watch: the time the first day not read whole yet is read up to */
    private const DAY = 'd';

    /** memory of a message beyond what the process holds: the files read from the database (its buffer
     *  of their base64 too), the base64 of the request and the request - a few times the files */
    public const MEMORY_PER_BYTE  = 8;
    private const MEMORY_SLACK    = 16 * 1024 * 1024;

    /** the site module whose binding (Accounts::binding()) the run sends by, with env, account and kind as queued */
    protected $site = '';
    /** Account::TEST or Account::PRODUCTION */
    protected $env = '';
    /** the name of the binding */
    protected $account = '';
    /** Account::BASIC, CERT or HOSTED */
    protected $kind = '';
    /** fingerprintOf() the login: a new password or certificate is a login that has to show its box */
    protected $acct_id = '';
    /** the box the login belonged to when the run was queued */
    protected $sender_box = '';
    protected $camp_slice = '';
    protected $msg_slice = '';
    /** the short id of the campaign item: a message is referenced <camp_short>-<its order from 1> */
    protected $camp_short = '';
    /** the id of the Snapshot and its manifest */
    protected $snapshot = '';
    protected $manifest = '';
    /** The first recipient index the run has not decided - no message tried, no box found inactive; it
     *  only grows. The next message to try is max(position i, cursor): a step that died sending message
     *  k leaves cursor k+1, i still k. */
    protected $cursor = '';
    /** the index whose message may be on its way, and since when; set before the request leaves */
    protected $inflight = '';
    protected $inflight_at = '';
    /** JSON index => the dmID ISDS took the message under */
    protected $dm_ids = '';
    /** JSON index => the time a message of unknown fate was tried: looked up, never sent again */
    protected $uncertain = '';
    /** JSON index => the status ISDS refused the message with */
    protected $failed = '';
    /** JSON index => Schema::BOX_INACTIVE: the box took no messages when its message was due */
    protected $skips = '';
    /** the attempts in a row that ended alike (SendPace::streak()): its breakers outlast a step */
    protected $streak = '';
    /** the last state the run gave the campaign */
    protected $camp_state = '';
    /** the time ISDS last said which box the login belongs to */
    protected $owner_at = '';
    /** who stopped the sending; '' = no one. Nothing is tried after it, so the cursor stays where the stop found it */
    protected $stop_by = '';
    /** the time following delivery started; '' = the run does not follow it */
    protected $watch_from = '';
    /** the time the state changes are read up to */
    protected $watch_last = '';
    /** the last local day (Y-m-d) read whole for the acceptances after 10 days ISDS records after its midnight */
    protected $watch_day = '';
    /** the time the list of sent messages was last read through for delivery, '' = never */
    protected $watch_rec = '';
    /** JSON index => the delivery of the message, an entry of StatusSync::entry() */
    protected $dm_state = '';
    /** JSON index => 1: message items that do not show their dm_state yet */
    protected $dm_dirty = '';

    /** messages of this step sent without the state of their box */
    private int $unchecked = 0;
    /** a step of watch that writes no message item reads no snapshot and leaves the pages of the slice be */
    private bool $quiet = false;
    /** @var ?list<array<string, string>> the frozen recipients, read by the first item a step of watch writes */
    private ?array $rows = null;
    /** recipients of the snapshot this step sends to */
    private int $total = 0;
    /** memory_limit before the step raised it, null = not raised */
    private ?string $memoryBefore = null;

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (self::OWN as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    /**
     * A new run of $campaign, in the caller's transaction: its snapshot saved, prep from the first
     * frozen recipient, nothing tried yet
     * @param array<string, string> $props the own properties of the account, the slices and camp_short
     * @throws BatchFailed BatchRun::NO_TRANSACTION as start()
     */
    public static function begin(string $campaign, string $uid, Snapshot $snapshot, array $props): static
    {
        $snapshot->save();
        return static::start($props + [
            'subject'  => $campaign,
            'user'     => $uid,
            'position' => ['p' => self::PREP, 'i' => 0],
            'snapshot' => (string) $snapshot->getId(),
            'manifest' => $snapshot->manifest(),
            'cursor'   => '0',
        ]);
    }

    /** a run of $campaign was started, whatever became of it - a campaign is sent once */
    public static function existsFor(string $campaign): bool
    {
        return self::recordsOfSubject(strtolower($campaign)) !== [];
    }

    /**
     * acct_id of $account, so that the code starting a run and its steps compute it alike
     * @throws ConfigException the certificate cannot be read
     */
    public static function fingerprintOf(Account $account): string
    {
        return $account->fingerprint(Secret::key(self::FINGERPRINT));
    }

    /**
     * Stop: what was never tried does not go, what may have gone is still looked up - the steps do it,
     * with no account and no ISDS. A run that would not go on by itself (in error, waiting, its step
     * dead) goes on at once. A run following delivery has nothing left to stop, whatever the stop and
     * the cursor say: it ends by itself.
     * Abandon ($abandon): gives up a run a stop can no longer end (abandonable()) - what may have gone
     * stays uncertain for a person to verify. Only asked for: a stop never gives a run up, so a stale
     * Stop form cannot do what cannot be undone.
     * @return string '' = stopping; ABANDONED; or why not: BUSY, FORBIDDEN, WRONG_STATE (no sending, one
     *                that ended, or abandon of a run a stop still ends), ALL_SENT, CONFIRM_ABANDON, LEASE (in
     *                error, its last step may still work)
     */
    public static function stop(string $campaign, string $uid, bool $abandon): string
    {
        return self::underLock($campaign, $uid, static function (self $run) use ($uid, $abandon): string {
            $states = static::states();
            if (in_array($run->state(), [$states->done, $states->ended], true)) {
                return 'WRONG_STATE';
            }
            $finds = $run->stopFinds();
            if ($finds === self::CONFIRM_ABANDON) {
                if (!$abandon) {
                    return self::CONFIRM_ABANDON;
                }
                $run->end($uid, self::ABANDONED);
                return self::ABANDONED;
            }
            if ($abandon) {
                return 'WRONG_STATE';
            }
            if ($finds === self::ALL_SENT) {
                return self::ALL_SENT;
            }
            // the run stands till Continue can move it: "stopping" would not be true yet
            if (($run->state() === $states->error) && $run->stepMayStillWork(static::now())) {
                return 'LEASE';
            }
            if ((string) $run->stop_by === '') {
                $run->stop_by = $uid;
                $run->update(['stop_by']);
            }
            if ($run->mayResume(static::now())) {
                $run->resume($uid);
            }
            return '';
        });
    }

    /** the uid of who stopped the sending; '' = no one */
    public function stoppedBy(): string
    {
        return (string) $this->stop_by;
    }

    /**
     * A run in error a stop can no longer end - stopped already, every message tried, or following
     * delivery: Continue, or giving it up. Read without the lock it only tells what to offer.
     */
    public function abandonable(): bool
    {
        return $this->stopFinds() === self::CONFIRM_ABANDON;
    }

    /**
     * What a stop of a run not ended finds: CONFIRM_ABANDON in error with nothing it could stop (abandonable()),
     * ALL_SENT with nothing left to stop - a sending all sent does not end as stopped -, '' something to stop
     */
    private function stopFinds(): string
    {
        $stopped = ((string) $this->stop_by !== '');
        $over    = $this->watching() || (!$stopped && $this->triedAll());
        if (($this->state() === static::states()->error) && ($over || $stopped)) {
            return self::CONFIRM_ABANDON;
        }

        return $over ? self::ALL_SENT : '';
    }

    /**
     * Every message tried: the cursor past the last recipient. A manifest that does not say how many
     * there are tells nothing, and the stop goes on - its steps fail on it, and the run in error can
     * then be given up.
     */
    private function triedAll(): bool
    {
        try {
            return (int) $this->cursor >= Snapshot::recipientCount((string) $this->manifest);
        } catch (BatchFailed) {
            return false;
        }
    }

    /**
     * Continue: a run in error, waiting, hanging or with its step dead goes on now
     * @return string '' = going on; or why not: BUSY, FORBIDDEN, LEASE, WRONG_STATE
     */
    public static function resumeFor(string $campaign, string $uid): string
    {
        return self::underLock($campaign, $uid, static function (self $run) use ($uid): string {
            $run->resume($uid);
            return '';
        });
    }

    /**
     * A person found a message of unknown fate not among the sent ones of the box: the record fails it
     * as verified (Schema::VERIFIED), then its item - so it counts as not sent and may be sent again.
     * Only once the sending is over: following delivery, done, or given up and its end run. A stop
     * alone (isOver()) is not enough: the run still looks up a message that may have gone, and the end
     * of a run given up marks such messages uncertain - over a verification it did not read.
     * The item is written again on a repeat, as the record says it: a write that failed leaves it uncertain.
     * @param string $msg long id of the message item
     * @return string '' = verified now; VERIFIED = before, the record unchanged; or why not: BUSY,
     *                FORBIDDEN, WRONG_STATE, UNKNOWN_MESSAGE, NOT_UNCERTAIN, SETTINGS, Snapshot::CHANGED
     */
    public static function verify(string $campaign, string $uid, string $msg): string
    {
        $msg  = strtolower($msg);
        $name = AA::$perm->getUserName($uid);
        $by   = ($name === $uid) ? $uid : "$uid $name";
        // the recipients are frozen and the rules of the slices change by hand: read before the locks
        $index = static function (self $run) use ($msg): int|string {
            $k = $run->indexOf($msg);
            if (is_null($k)) {
                return self::UNKNOWN_MESSAGE;
            }
            // nothing is stored into slices that break a rule, as in work()
            $run->requireSliceRules();
            return $k;
        };
        return self::underLock($campaign, $uid, static function (self $run, int $k): string {
            if (!$run->verifiable()) {
                return 'WRONG_STATE';
            }
            if ((SendMaps::entries((string) $run->failed)[$k] ?? null) === Schema::VERIFIED) {
                // the record decides: one edited since into another fate is not written over
                return (($run->maps()->fates()[$k] ?? null) === Schema::MESSAGE_STATE_FAILED) ? self::VERIFIED : self::NOT_UNCERTAIN;
            }
            if (!array_key_exists($k, $run->maps()->unsettled())) {
                return self::NOT_UNCERTAIN;
            }
            // the record first: an item written and then lost to a failure would never be sent again
            $run->failed = SendMaps::with((string) $run->failed, $k, Schema::VERIFIED);
            $run->update(['failed']);
            return '';
        }, $index, static function (self $run, string $said) use ($msg, $by): void {
            if (!in_array($said, ['', self::VERIFIED], true)) {
                return;
            }
            try {
                $run->items()->mark($msg, Schema::MESSAGE_STATE_FAILED, Schema::EVENT_VERIFIED, static::now(), code: Schema::VERIFIED, once: $said === self::VERIFIED, by: $by);
            } catch (\Throwable $e) {
                // the record says it, a repeat writes the item; the verification itself is done and is logged
                AA_Log::write(Toexecute::FAILED_EVENT, $run->selector(), Toexecute::failureParams($run->getId(), $e));
            }
        });
    }

    /**
     * Nothing looks up a message of unknown fate any more, nor marks it: following delivery (in error
     * too) - not while a repeated look still runs -, done, or given up once its end ran, or failed
     * (after_close) and a person must not wait for it for ever. Public for the page of a sending
     * (Gate::progress()): it offers Verify by it, and counts uncertain messages as for a person by it.
     * Of a run given up it asks the queue for its close row.
     */
    public function verifiable(): bool
    {
        return match ($this->state()) {
            self::DONE  => true,
            self::ENDED => !$this->closeWaits() || $this->closeFailed(),
            default     => $this->watching() && (($this->position()['p'] ?? null) === self::WATCH),
        };
    }

    /** the name of the DB_AA::lock() of a campaign: queueing, stopping, Continue and verifying take it, one at a time */
    public static function lockName(string $campaign): string
    {
        return 'isds-campaign:' . strtolower($campaign);
    }

    /**
     * $do with the run of $campaign locked, under the lock of the campaign - released after the
     * commit, so whoever waits for it sees what $do wrote. Only for $uid that may control the run,
     * asked before the locks too: no one else holds them up.
     * @param \Closure(self, mixed): string     $do      with what $prepare gave
     * @param ?\Closure(self): (int|string)     $prepare before the locks, with the run as stored: a
     *                                                   string refuses, anything else goes to $do
     * @param ?\Closure(self, string): void     $then    with the run and what $do said, after the commit,
     *                                                   still under the lock of the campaign
     * @return string what $do says; BUSY, or the code it was refused with
     */
    private static function underLock(string $campaign, string $uid, \Closure $do, ?\Closure $prepare = null, ?\Closure $then = null): string
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('A sending is stopped, continued or verified outside any transaction');
        }
        $campaign = strtolower($campaign);
        $id       = (preg_match('/^[0-9a-f]{32}\z/', $campaign) === 1) ? array_key_first(self::recordsOfSubject($campaign)) : null;
        if (is_null($id)) {
            return 'WRONG_STATE';
        }
        $stored = static::get((string) $id);
        if (!($stored?->mayControl($uid) ?? false)) {
            return self::FORBIDDEN;
        }
        try {
            $prepared = $prepare ? $prepare($stored) : null;
        } catch (BatchFailed $e) {
            return $e->reason;
        }
        if (is_string($prepared)) {
            return $prepared;
        }
        if (!static::lockCampaign($campaign)) {
            return self::BUSY;
        }
        try {
            try {
                [$run, $said] = DB_AA::transaction(static function () use ($id, $uid, $do, $prepared): array {
                    $run = static::lockForUpdate((string) $id) ?? throw new BatchFailed('WRONG_STATE');
                    return [$run, $run->mayControl($uid) ? $do($run, $prepared) : self::FORBIDDEN];
                });
            } catch (BatchFailed $e) {
                return $e->reason;
            }
            if ($then) {
                $then($run, $said);
            }
            return $said;
        } finally {
            static::unlockCampaign($campaign);
        }
    }

    protected static function states(): BatchStates
    {
        return new BatchStates(self::SENDING, self::ERROR, self::DONE, self::ENDED);
    }

    public function selector(): string
    {
        return 'IsdsSend_' . $this->subject;
    }

    public function mayControl(string $uid): bool
    {
        return Access::userMay($uid, (string) $this->camp_slice);
    }

    /**
     * A send step waits for CreateMessage - one cut short may have sent its message - and must not be
     * taken for dead: the last message it starts with MIN_SEND left takes the check of its box, the
     * request with SendPace::RECORD_RESERVE, and a little for the rest. A look step reads a page of the
     * list, and more only while its budget lasts; the last one and the items it then writes fit the same.
     * A watch step starts a call only while its budget lasts, but the check of the box (checkedClient())
     * may come right before it: two calls, the record and a chunk of items.
     * prep and stop call no ISDS: a step of theirs that died holds Continue back no longer than one of
     * any run.
     */
    protected function overrun(): int
    {
        return match ($this->position()['p'] ?? self::PREP) {
            self::SEND, self::LOOK => Client::TIMEOUT + Client::CREATE_TIMEOUT + SendPace::RECORD_RESERVE + 10,
            self::WATCH            => 2 * Client::TIMEOUT + SendPace::RECORD_RESERVE + 10,
            default                => parent::overrun(),
        };
    }

    /** after a pause till its end; after a failed step a minute more for each failure */
    protected function retryDelay(int $failures): int
    {
        return ($failures === 0) ? max(1, SendPace::pauseEnd($this->position(), static::now()) - static::now()) : 60 * $failures;
    }

    /** Continue gives a pause the full time again, reads the list from its start and calls ISDS over the
     *  full span again: a reading a step failed on would fail again */
    protected function positionOnResume(array $position): array
    {
        return array_diff_key($position, array_flip([...self::PAUSE_KEYS, self::READING, self::SPAN]));
    }

    /** all messages tried, the run follows their delivery - read from the record, not the position */
    public function watching(): bool
    {
        return (string) $this->watch_from !== '';
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        // a run lasts hours: a template, a feed or a password taken off meanwhile would get what the
        // open slices hold - so nothing is stored, not even the error into the campaign
        $this->requireSliceRules();
        try {
            return $this->withSlices(fn(): BatchStepResult => $this->workFrom($from, $budget));
        } finally {
            // the next task of the queue process gets the limit it had
            if (!is_null($this->memoryBefore)) {
                $this->restoreMemoryLimit($this->memoryBefore);
                $this->memoryBefore = null;
            }
        }
    }

    private function workFrom(array $from, BatchBudget $budget): BatchStepResult
    {
        $failure     = null;
        $this->quiet = false;
        $phase       = (string) ($from['p'] ?? self::PREP);
        // a phase of newer code, run by an older tree over the same database, writes nothing
        if (!in_array($phase, self::PHASES, true)) {
            throw new \LogicException("A sending has no phase $phase");
        }
        $watch = ($phase === self::WATCH);
        try {
            $inflight = (string) $this->inflight;
            if (($inflight !== '') && !$this->settleInflight()) {
                // ended, or Continue or another step took the run: the core drops this result
                return new BatchStepResult($from, false);
            }
            if ($watch) {
                // every message was tried, nothing is left to stop; the frozen recipients are read only
                // for the items a round writes
                return $this->watch($from, $budget);
            }
            // the recipients only: the attachments are read when sending, past the account
            $snapshot = $this->snapshot(false);
            $msg      = ($inflight === '') ? null : ($snapshot->recipients()[(int) $inflight]['msg'] ?? null);
            if (!is_null($msg)) {
                $this->items()->mark($msg, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, static::now());
            }
            // after Continue the campaign shows the error the run stopped at; prep leaves a queued one till its end
            if (($phase === self::SEND) || ($this->camp_state === Schema::CAMPAIGN_STATE_ERROR)) {
                $this->campaignIs(Schema::CAMPAIGN_STATE_SENDING);
            }
            // before the account, the files and ISDS: a stop must work while they fail
            if (((string) $this->stop_by !== '') && in_array($phase, [self::PREP, self::SEND], true)) {
                $phase = self::STOP;
                $from  = ['p' => self::STOP, 'i' => 0];
            }
            return match ($phase) {
                self::PREP => $this->prepare($from, $budget, $snapshot),
                self::SEND => $this->send($from, $budget, count($snapshot->recipients())),
                self::STOP => $this->stopping($from, $budget, $snapshot),
                self::LOOK => $this->look($from, $budget, $snapshot),
            };
        } catch (\Throwable $failure) {
            $this->campaignFails($failure, $watch);
            throw $failure;
        } finally {
            try {
                if (!$this->quiet) {
                    $this->invalidate();
                }
            } catch (\Throwable $e) {
                // the failure of the step is the one the run shows
                if (is_null($failure)) {
                    $this->campaignFails($e, $watch);
                    throw $e;
                }
            }
        }
    }

    /**
     * The run ends in error, so the campaign must not go on showing it sending - the code only, as the run's.
     * Once following delivery started - in a step of watch, or in the step of look that started it - the
     * campaign shows the end of the sending, and what fails is the run's only.
     */
    private function campaignFails(\Throwable $e, bool $watch): void
    {
        if ($watch || $this->watching()) {
            return;
        }
        try {
            $this->campaignIs(Schema::CAMPAIGN_STATE_ERROR, ($e instanceof BatchFailed) ? $e->reason : self::INTERNAL);
        } catch (\Throwable) {
            // the run keeps its error all the same - a campaign item that cannot be written must not hide it
        }
    }

    /**
     * The message in flight when a step died, or threw after it was sent, is uncertain: the record
     * says so first, so that it is never sent again, whatever happens to its item
     * @return bool recorded, and the run still this step's
     */
    private function settleInflight(): bool
    {
        return $this->recordResult(static function (self $run): bool {
            $index = (string) $run->inflight;
            if ($index === '') {
                return false;
            }
            $run->uncertain   = SendMaps::with((string) $run->uncertain, (int) $index, (int) $run->inflight_at);
            $run->cursor      = (string) max((int) $run->cursor, (int) $index + 1);
            $run->inflight    = '';
            $run->inflight_at = '';
            return true;
        }) === true;
    }

    /**
     * A message item for each frozen recipient, queued or skipped with its reason; without a network
     * or an account, so it runs whatever ISDS does. An item made already stays as it is: a repeated
     * step is harmless.
     */
    private function prepare(array $from, BatchBudget $budget, Snapshot $snapshot): BatchStepResult
    {
        $rows  = $snapshot->recipients();
        $items = $this->items();
        $at    = $this->inChunks($rows, (int) ($from['i'] ?? 0), $budget, function (int $k, array $row) use ($items): void {
            $items->create($row, (string) $this->env, (string) $this->sender_box, static::now());
        });
        if ($at < count($rows)) {
            return new BatchStepResult(['p' => self::PREP, 'i' => $at], false, done: $at, total: count($rows));
        }
        $this->campaignIs(Schema::CAMPAIGN_STATE_SENDING);
        return new BatchStepResult(['p' => self::SEND, 'i' => 0], false, done: $at, total: count($rows));
    }

    /**
     * One message at a time while the step has MIN_SEND seconds left. Before a request leaves, the
     * record says it may be on its way (inflight); what came of it goes into the record first, then
     * into the message item - a step that dies in between leaves the message uncertain, never sent
     * twice. A recipient skipped by its frozen reason costs no request, and an index the record has
     * the fate of (skips too) lies behind the cursor. The files are read once a step, after their
     * memory is there and the account is checked, and every message shares them.
     */
    private function send(array $from, BatchBudget $budget, int $n): BatchStepResult
    {
        $this->total     = $n;
        $this->unchecked = 0;
        $early           = $this->early($from);
        if ($early) {
            return $early;
        }
        if ($this->next($from) >= $n) {
            return $this->allTried();
        }
        if (preg_match('/^\d{1,20}\z/', (string) $this->camp_short) !== 1) {
            throw new \LogicException('A sending references its messages by the short id of its campaign');
        }
        // nothing during the maintenance, not even whose the login is
        $maintenance = $this->maintenancePause($from);
        if ($maintenance) {
            return $maintenance;
        }
        $this->requireMemory();
        $client = $this->checkedClient();
        if (is_string($client)) {
            return $this->paused($from, $client);
        }
        $snapshot = $this->snapshot(true);
        $rows     = $snapshot->recipients();
        $at       = $from;
        while ($budget->secondsLeft() >= self::MIN_SEND) {
            // each record written brings the stop of the record as it is now: no box checked after it
            if ((string) $this->stop_by !== '') {
                return $this->result(['p' => self::STOP, 'i' => 0]);
            }
            $at['i'] = $k = $this->next($at);
            if ($k >= $n) {
                return $this->allTried();
            }
            if ($rows[$k]['reason'] !== '') {
                ++$at['i'];
                continue;
            }
            $maintenance = $this->maintenancePause($at);
            if ($maintenance) {
                return $maintenance;
            }
            $next = $this->sendTo($k, $rows[$k], $snapshot->letter(), $client, $at);
            if ($next instanceof BatchStepResult) {
                return $next;
            }
            $at = $next;
        }
        return $this->result($at);
    }

    /**
     * The message to recipient $k, from the check of its box to its item showing what became of it
     * @param array<string, string> $row Snapshot::recipients() row
     * @param array<string, mixed>  $at  the position, at $k
     * @return array<string, mixed>|BatchStepResult the position to go on from, or the end of the step
     * @throws BatchFailed the login refused, a configuration that cannot work, the same refusal again and again
     */
    private function sendTo(int $k, array $row, Letter $letter, Client $client, array $at): array|BatchStepResult
    {
        try {
            $state = $client->checkDataBox($row['box']);
        } catch (TransportException|UnavailableException) {
            return $this->paused($at, Outcome::UNAVAILABLE);
        } catch (AuthException|ConfigException $e) {
            throw new BatchFailed(Outcome::of($e)->code);
        } catch (IsdsException $e) {
            // as Outcome::of(): a subclass AA does not know yet says nothing
            if ($e::class !== IsdsException::class) {
                throw $e;
            }
            // ISDS refused the check, e.g. 1007 or 2011 for an id of a form it does not take
            $state = Client::NO_STATE;
        }
        if ($state === Client::NO_STATE) {
            // no word on this box: the message gets an answer of its own, an inactive box only a refusal
            ++$this->unchecked;
        } elseif ($state !== 1) {
            return $this->skipInactive($k, $row['msg'], $at);
        }
        $message = new Message($row['box'], $letter->annotation, $letter->files, $this->senderRef($k), $letter->toHands);
        $now     = static::now();
        $stopped = false;
        $going   = $this->beforeAction(static function (self $run) use ($k, $now, &$stopped): bool {
            // stopped meanwhile, a message on its way already, or one the record has the fate of - also
            // below a cursor lowered by hand
            $stopped = ((string) $run->stop_by !== '');
            if ($stopped || ((string) $run->inflight !== '') || ($k < (int) $run->cursor) || array_key_exists($k, $run->maps()->decided())) {
                return false;
            }
            $run->inflight    = (string) $k;
            $run->inflight_at = (string) $now;
            return true;
        }, Client::CREATE_TIMEOUT + SendPace::RECORD_RESERVE);
        if (!$going) {
            return $this->result($stopped ? ['p' => self::STOP, 'i' => 0] : $at);
        }
        try {
            $this->items()->mark($row['msg'], Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, $now, '', '', $message->senderRef);
        } catch (\Throwable $e) {
            // nothing left: the next step must not take the message for one of unknown fate
            $this->recordResult(static function (self $run): bool {
                $run->inflight    = '';
                $run->inflight_at = '';
                return true;
            });
            throw $e;
        }
        try {
            $result = $client->createMessage($message);
        } catch (\Throwable $e) {
            $result = $e;
        }
        $outcome = Outcome::of($result);
        $goesOn  = $this->recorded($k, $outcome);
        if (is_null($goesOn)) {
            // Continue or another step took the run: that one settles the message as uncertain
            return $this->result($at);
        }
        $this->items()->markOutcome($row['msg'], $outcome, static::now());
        $at = ['i' => ($outcome->state === Schema::MESSAGE_STATE_QUEUED) ? $k : $k + 1] + $at;
        // only an answer to CreateMessage ends a pause: a dead endpoint for sending behind a live one
        // for the check would otherwise start every pause afresh and never reach the 6 h stop
        if (in_array($outcome->state, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_FAILED], true)) {
            $at = array_diff_key($at, array_flip(self::PAUSE_KEYS));
        }
        if (($outcome->then === Outcome::RETHROW) && ($result instanceof \Throwable)) {
            throw $result;
        }
        if ($outcome->then === Outcome::FAIL) {
            throw new BatchFailed($outcome->code);
        }
        $breaker = SendPace::breaker((string) $this->streak);
        return match (true) {
            !$goesOn                                                              => $this->result($at),
            // stopped while the request was on its way: the stop works without ISDS, no need to wait for it
            (string) $this->stop_by !== ''                                         => $this->result(['p' => self::STOP, 'i' => 0]),
            ($outcome->then === Outcome::PAUSE) || ($breaker === Outcome::PAUSE) => $this->paused($at, Outcome::UNAVAILABLE),
            $breaker === Outcome::FAIL                                            => throw new BatchFailed(self::REFUSED, '', SendPace::refusalDetail($outcome->isdsCode)),
            default                                                               => $at,
        };
    }

    /**
     * What came of the request for $k, into the record before anything else: its map, the cursor
     * past it unless it never left, the streak
     * @return ?bool as recordResult()
     */
    private function recorded(int $k, Outcome $outcome): ?bool
    {
        return $this->recordResult(static function (self $run) use ($k, $outcome): bool {
            match ($outcome->state) {
                Schema::MESSAGE_STATE_SENT      => $run->dm_ids = SendMaps::with((string) $run->dm_ids, $k, $outcome->dmId),
                Schema::MESSAGE_STATE_FAILED    => $run->failed = SendMaps::with((string) $run->failed, $k, $outcome->isdsCode),
                Schema::MESSAGE_STATE_UNCERTAIN => $run->uncertain = SendMaps::with((string) $run->uncertain, $k, (int) $run->inflight_at),
                default                         => null,
            };
            // a message that never left (queued) goes out again
            if ($outcome->state !== Schema::MESSAGE_STATE_QUEUED) {
                $run->cursor = (string) max((int) $run->cursor, $k + 1);
            }
            $run->streak      = SendPace::streak((string) $run->streak, $outcome);
            $run->inflight    = '';
            $run->inflight_at = '';
            return true;
        });
    }

    /**
     * A box that takes no messages: skipped for good, its box not checked again
     * @param array<string, mixed> $at
     * @return array<string, mixed>|BatchStepResult as sendTo()
     */
    private function skipInactive(int $k, string $msg, array $at): array|BatchStepResult
    {
        $goesOn = $this->recordResult(static function (self $run) use ($k): bool {
            $run->skips  = SendMaps::with((string) $run->skips, $k, Schema::BOX_INACTIVE);
            $run->cursor = (string) max((int) $run->cursor, $k + 1);
            return true;
        });
        if (!is_null($goesOn)) {
            $this->items()->mark($msg, Schema::MESSAGE_STATE_SKIPPED, Schema::EVENT_SKIPPED, static::now(), '', Schema::BOX_INACTIVE);
        }
        return $goesOn ? ['i' => $k + 1] + $at : $this->result($at);
    }

    /** how many recipients, in their frozen order, the sending got to - tried, skipped or in flight */
    public function tried(): int
    {
        return max((int) $this->cursor, ((string) $this->inflight === '') ? 0 : (int) $this->inflight + 1);
    }

    /** what the record keeps of each recipient, as this instance read or wrote it */
    public function maps(): SendMaps
    {
        return new SendMaps((string) $this->dm_ids, (string) $this->uncertain, (string) $this->failed, (string) $this->skips, (string) $this->inflight, (int) $this->inflight_at, (string) $this->dm_state);
    }

    /**
     * The messages whose item does not show the delivery the record keeps yet (dm_dirty): while the run
     * follows delivery, its steps write them. Of a run that ended they mean nothing.
     * @return array<int, mixed> index => 1
     * @throws \JsonException dm_dirty edited into no JSON
     */
    public function unshown(): array
    {
        return SendMaps::entries((string) $this->dm_dirty);
    }

    /** the reference of the message to recipient $k - ISDS lists it, a person finds the message by it */
    public function senderRef(int $k): string
    {
        return $this->camp_short . '-' . ($k + 1);
    }

    /**
     * The frozen recipients, without the files
     * @return list<array<string, string>> Snapshot::recipients()
     * @throws BatchFailed Snapshot::CHANGED
     */
    public function recipients(): array
    {
        return $this->snapshot(false)->recipients();
    }

    /**
     * How far the sending got - from the record and the frozen recipients, no message item, no ISDS.
     * Each recipient counts as the end of the run counts it (SendMaps::fatesOf()), by the maps and the
     * frozen reasons, not by the cursor: a message in flight is uncertain, a recipient skipped by its
     * reason is skipped before the cursor gets to it too. One never tried is stopped once nothing sends
     * it any more (isOver()), otherwise queued. The counts add up to total unless a hand-edited record
     * names indexes beyond the recipients; until is the end of a pause, 0 = none - following delivery waits for its rounds, not
     * for ISDS. delivered and accepted as SendMaps::delivery().
     * Public for the page of a sending (Gate::progress()); a progressExtra() of this run must call it
     * too, so that both show the same figures.
     * @return array{total: int, sent: int, failed: int, uncertain: int, skipped: int, stopped: int, queued: int, until: int, delivered: int, accepted: int}
     * @throws \JsonException|BatchFailed a record edited into no JSON, recipients not as frozen
     */
    public function progressFigures(): array
    {
        return $this->figuresOf($this->recipients());
    }

    /**
     * progressFigures() of the frozen recipients read already: the page lists the messages of the same reading
     * @param list<array<string, string>> $rows recipients()
     * @return array{total: int, sent: int, failed: int, uncertain: int, skipped: int, stopped: int, queued: int, until: int, delivered: int, accepted: int}
     * @throws \JsonException a record edited into no JSON
     */
    public function figuresOf(array $rows): array
    {
        $now   = static::now();
        // a position left by a run in error waits for nothing
        $until = (($this->state() === self::SENDING) && !$this->watching()) ? SendPace::pauseEnd($this->position(), $now) : 0;
        return array_merge($this->maps()->tally($rows, $this->isOver()), $this->maps()->delivery(), [
            'total' => count($rows),
            'until' => ($until > $now) ? $until : 0,
        ]);
    }

    /**
     * The source items of the recipients the sending left unsent - never tried, stopped as the page
     * counts them (progressFigures()); never one that may have gone, failed or was skipped
     * @return list<string> long ids
     * @throws \JsonException|BatchFailed a record edited into no JSON, recipients not as frozen
     */
    public function unsentSources(): array
    {
        $rows = $this->recipients();

        return array_map(static fn(int $k): string => $rows[$k]['src'], array_keys($this->maps()->fatesOf($rows, $this->isOver()), Schema::MESSAGE_STATE_STOPPED, true));
    }

    /**
     * Whether the letter may go once more to a recipient of $fate (SendMaps::fatesOf()), alone: only a
     * fate no step of the sending changes any more - failed (verified by a person and not found by the
     * lookup too) and stopped. The one rule the copy to one recipient and the page share.
     */
    public static function resendable(string $fate): bool
    {
        return in_array($fate, [Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_STOPPED], true);
    }

    /**
     * The source item of the recipient of message $msg, when the letter may go to it once more
     * (resendable()) - from the recipients frozen and the record, never what the item of the message
     * shows now. Needs no lock of the campaign, as unsentSources(): the record is only read, a fate it
     * accepts is final, and a reading in the middle of a write gives at most the fate of before -
     * queued or uncertain, refused -, never one who may still get the letter.
     * @param string $msg long id of the message item
     * @return ?string long id; '' = its recipient may get the letter or was left out; null = no message of the sending
     * @throws \JsonException|BatchFailed a record edited into no JSON, recipients not as frozen
     */
    public function resendSource(string $msg): ?string
    {
        $rows = $this->recipients();
        $k    = self::indexIn($rows, $msg);
        if (is_null($k)) {
            return null;
        }

        return self::resendable($this->maps()->fatesOf($rows, $this->isOver())[$k] ?? '') ? $rows[$k]['src'] : '';
    }

    /**
     * The index of message $msg among the recipients frozen; null = no message of the sending
     * @param string $msg long id of the message item, in any case
     * @throws BatchFailed recipients not as frozen
     */
    private function indexOf(string $msg): ?int
    {
        return self::indexIn($this->recipients(), $msg);
    }

    /** @param list<array<string, string>> $rows recipients() */
    private static function indexIn(array $rows, string $msg): ?int
    {
        $k = array_search(strtolower($msg), array_map(static fn(array $row): string => strtolower($row['msg']), $rows), true);

        return ($k === false) ? null : $k;
    }

    /**
     * Nothing sends a recipient never tried any more: a person stopped the run, its sending is over
     * (following delivery sends nothing), or it ended
     */
    public function isOver(): bool
    {
        return ((string) $this->stop_by !== '') || $this->watching() || in_array($this->state(), [self::DONE, self::ENDED], true);
    }

    /**
     * Every message tried, or skipped by its reason: the cursor says so past the last ones too, and
     * the sending goes on to what became of them
     */
    private function allTried(): BatchStepResult
    {
        $n = $this->total;
        if ((int) $this->cursor < $n) {
            $this->recordResult(static function (self $run) use ($n): bool {
                $run->cursor = (string) max((int) $run->cursor, $n);
                return true;
            });
        }
        return $this->result(['p' => self::LOOK, 'i' => 0]);
    }

    /**
     * Nothing more is sent: what was never tried is stopped, a recipient skipped for its reason gets
     * an item if prep did not make one. From the record, not the items, and from the cursor - nothing
     * moves it once the stop phase starts; only the frozen recipients are read. Then look, as at the end
     * of any sending.
     */
    private function stopping(array $from, BatchBudget $budget, Snapshot $snapshot): BatchStepResult
    {
        $rows  = $snapshot->recipients();
        $items = $this->items();
        $known = $this->maps()->decided();
        $at    = $this->inChunks($rows, max((int) $this->cursor, (int) ($from['i'] ?? 0)), $budget, function (int $k, array $row) use ($items, $known): void {
            $this->cancel($k, $row, $items, $known);
        });
        $next  = ($at < count($rows)) ? ['p' => self::STOP, 'i' => $at] : ['p' => self::LOOK, 'i' => 0];
        return new BatchStepResult($next, false, done: $at, total: count($rows));
    }

    /**
     * $write of each row from $at on, CHUNK of them in a transaction - a commit each would cost a sync
     * of the disk each - while the budget lasts
     * @template T
     * @param list<T>                $rows
     * @param \Closure(int, T): void $write
     * @return int the index to go on from
     */
    private function inChunks(array $rows, int $at, BatchBudget $budget, \Closure $write): int
    {
        while (($at < count($rows)) && !$budget->spent()) {
            $chunk = array_slice($rows, $at, self::CHUNK, true);
            DB_AA::transaction(static function () use ($chunk, $write): void {
                foreach ($chunk as $k => $row) {
                    $write($k, $row);
                }
            });
            $at += count($chunk);
        }
        return $at;
    }

    /**
     * The item of recipient $k as a stop leaves it: stopped when never tried, made skipped when prep
     * did not get to it; one the record has the fate of stays as it is
     * @param array<string, string> $row
     * @param array<int, mixed>     $known decided()
     */
    private function cancel(int $k, array $row, SendItems $items, array $known): void
    {
        if (array_key_exists($k, $known)) {
            return;
        }
        $now = static::now();
        if (!$items->create($row, (string) $this->env, (string) $this->sender_box, $now, true) && ($row['reason'] === '')) {
            $items->mark($row['msg'], Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED, $now, once: true);
        }
    }

    /**
     * What became of the messages of unknown fate, from the list of sent messages - after the account
     * is checked, as for sending. One found is sent, or failed when the antivirus check refused it;
     * one not listed past its wait failed, and one that may still be listed waits; one listed twice
     * stays uncertain for a person. The list is read while the budget lasts: a part of it decides
     * nothing, the next step reads on (l). The record first, then the items, which show uncertain also
     * where a step left them sending.
     */
    private function look(array $from, BatchBudget $budget, Snapshot $snapshot): BatchStepResult
    {
        $rows        = $snapshot->recipients();
        $this->total = count($rows);
        $open        = $this->maps()->unsettled();
        if ($open === []) {
            return $this->lookEnds($from, $rows);
        }
        $early = $this->early($from) ?? $this->maintenancePause($from);
        if ($early) {
            return $early;
        }
        $client = $this->checkedClient();
        if (is_string($client)) {
            return $this->paused($from, $client);
        }
        $wanted = [];
        foreach ($open as $k => $triedAt) {
            $wanted[$k] = ['ref' => $this->senderRef($k), 'box' => $rows[$k]['box'], 'at' => $triedAt];
        }
        // a further page only while the budget lasts: overrun() covers the last one
        $lookup = self::ask(fn(): Lookup => Lookup::resolve($client->sentMessages(...), $wanted, $snapshot->annotation(), static::now(), (array) ($from[self::READING] ?? []), static fn(): bool => !$budget->spent()));
        if (is_null($lookup)) {
            return $this->paused($from, Outcome::UNAVAILABLE);
        }
        // an answer ends a pause
        $at = array_diff_key($from, array_flip([...self::PAUSE_KEYS, self::READING]));
        if ($lookup->scan !== []) {
            return $this->result([self::READING => $lookup->scan] + $at);
        }
        $refused = array_filter($lookup->found, static fn(SentMessage $sent): bool => $sent->status === SentMessage::AV_REFUSED);
        if ($lookup->found || $lookup->missing) {
            $goesOn = $this->recordResult(static function (self $run) use ($lookup, $refused): bool {
                foreach (array_diff_key($lookup->found, $refused) as $k => $sent) {
                    $run->dm_ids = SendMaps::with((string) $run->dm_ids, $k, $sent->dmId);
                }
                foreach ($refused as $k => $sent) {
                    $run->failed = SendMaps::with((string) $run->failed, $k, (string) $sent->status);
                }
                foreach ($lookup->missing as $k) {
                    $run->failed = SendMaps::with((string) $run->failed, $k, Schema::NOT_FOUND);
                }
                return true;
            });
            if (is_null($goesOn)) {
                return new BatchStepResult($from, false);
            }
        }
        $items = $this->items();
        $now   = static::now();
        foreach ($lookup->found as $k => $sent) {
            $items->markFound($rows[$k]['msg'], $sent, $open[$k], $now);
        }
        foreach ($lookup->missing as $k) {
            $items->mark($rows[$k]['msg'], Schema::MESSAGE_STATE_FAILED, Schema::EVENT_LOOKUP_MISSING, $now, '', Schema::NOT_FOUND, once: true);
        }
        foreach ([...$lookup->unsure, ...array_keys($lookup->waiting)] as $k) {
            $items->mark($rows[$k]['msg'], Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, $now, once: true);
        }
        return ($lookup->until() > 0) ? $this->result(['u' => $lookup->until()] + $at, self::LOOKING) : $this->lookEnds($at, $rows);
    }

    /**
     * The end of look. A sending that sent messages goes on following their delivery: the campaign shows
     * its end first - the sending is over for people, whatever is delivered later -, then the record says
     * since when, the state changes to read from the start of the run. One that sent none ends now.
     * A look repeated after following started - its step failed later, Continue ran it again - only goes
     * on: following counts from the end of the sending, not from Continue.
     * @param list<array<string, string>> $rows
     */
    private function lookEnds(array $at, array $rows): BatchStepResult
    {
        if (StatusSync::indexOf(SendMaps::entries((string) $this->dm_ids)) === []) {
            return new BatchStepResult($at, true, done: $this->total, total: $this->total);
        }
        if ($this->watching()) {
            return $this->result(['p' => self::WATCH, 'n' => 0]);
        }
        $now = static::now();
        try {
            $this->campaignIs(self::endState($this->maps()->tally($rows)), '', [Schema::CAMPAIGN_FINISHED_AT => $now], true);
        } catch (\Throwable $e) {
            // tried again as the core tries the end of a run that failed: later each time, then the run errs
            AA_Log::write(Toexecute::FAILED_EVENT, $this->selector(), Toexecute::failureParams($this->getId(), $e));
            return new BatchStepResult($at, false, retry: 'FINAL_CHECK');
        }
        $goesOn = $this->record(['watch_from' => (string) $now, 'watch_last' => (string) $this->started, 'watch_day' => StatusSync::firstWatchDay($now)]);
        return is_null($goesOn) ? new BatchStepResult($at, false) : $this->result(['p' => self::WATCH, 'n' => 0]);
    }

    /**
     * A round of following delivery: the message items that do not show their delivery yet, then the
     * state changes, the days not read yet and the list of sent messages when due (readChanges()). What
     * ISDS told goes into the record first - a message this sending did not send is passed over -, then
     * into the items; an item leaves dm_dirty only when it shows what the record keeps now. Work the step
     * can do now goes on at once (StatusSync::pending()), the rest waits for the next whole hour; so does
     * ISDS that fails, with no limit - following spends nothing -, its detail saying why (readChanges()).
     * What needs a person fails the run; the campaign keeps the end of the sending.
     */
    private function watch(array $from, BatchBudget $budget): BatchStepResult
    {
        $now         = static::now();
        $this->total = Snapshot::recipientCount((string) $this->manifest);
        $this->quiet = true;
        $this->rows  = null;
        // a step of an older tree that does not know watch put the campaign into error: the sending ended
        // all the same. The record says what the campaign shows, so a round reads no snapshot for it.
        if (!in_array((string) $this->camp_state, [Schema::CAMPAIGN_STATE_DONE, Schema::CAMPAIGN_STATE_STOPPED], true)) {
            $this->rows = $this->snapshot(false)->recipients();
            $this->campaignIs(self::endState($this->maps()->tally($this->rows)), '', [], true);
        }
        $indexOf     = StatusSync::indexOf(SendMaps::entries((string) $this->dm_ids));
        $byIndex     = $this->byIndex();
        $times       = $this->watchTimes($byIndex, $now);
        if (is_null($times)) {
            return new BatchStepResult($from, false);
        }
        [$started, $last, $since, $rec] = $times;
        // a count edited to its end would fail every step, Continue too
        $n     = $from['n'] ?? 0;
        $at    = ['n' => (is_int($n) && ($n >= 0) && ($n < PHP_INT_MAX)) ? $n + 1 : 1] + array_diff_key($from, array_flip(self::PAUSE_KEYS));
        $dirty = SendMaps::entries((string) $this->dm_dirty);
        $state = SendMaps::entries((string) $this->dm_state);
        $done  = $this->showDelivery(array_keys($dirty), $state, $byIndex, $budget);
        $wait  = $this->early($from) ?? $this->maintenancePause($at);
        if ($wait) {
            $this->written($dirty, $state, $done);
            return $wait;
        }
        $read = ['last' => $last, 'day' => (string) $this->watch_day, 'at' => is_int($at[self::DAY] ?? null) ? $at[self::DAY] : 0, 'span' => self::span($at)];
        $w    = ['started' => $started, 'watch_from' => $since, 'watch_rec' => $rec, 'indexOf' => $indexOf];
        $got  = $this->readChanges($state, $read, $w + ['l' => $at[self::READING] ?? null], $budget, $now);
        $dirty += $got['changed'];
        $record = array_filter([
            'dm_state'   => ($got['changed'] === []) ? null : self::json($got['state']),
            'dm_dirty'   => ($got['changed'] === []) ? null : self::json($dirty),
            'watch_last' => ($got['read']['last'] === $last) ? null : (string) $got['read']['last'],
            'watch_day'  => ($got['read']['day'] === $read['day']) ? null : $got['read']['day'],
            'watch_rec'  => ($got['rec'] === $rec) ? null : (string) $got['rec'],
        ], is_string(...));
        // the record before the items that show it: a step that dies in between leaves them in dm_dirty
        if (($record !== []) && is_null($this->record($record))) {
            return new BatchStepResult($from, false);
        }
        [$state, $read] = [$got['state'], $got['read']];
        $done = $this->showDelivery(array_keys(self::stillDirty($dirty, $state, $done)), $state, $byIndex, $budget, $done);
        $left = $this->written($dirty, $state, $done);
        $at   = array_filter([self::SPAN => ($read['span'] < StatusSync::MAX_SPAN) ? $read['span'] : 0, self::DAY => $read['at'], self::READING => $got['l']])
            + array_diff_key($at, [self::SPAN => true, self::DAY => true, self::READING => true]);
        // a minute past the time ISDS may still record the changes of the next whole hour (LAG)
        $next = StatusSync::boundary($now) + StatusSync::ROUND + StatusSync::LAG + 60;
        if (!is_null($got['failed'])) {
            return $this->result(['u' => $next] + $at, Outcome::UNAVAILABLE, $got['failed']);
        }
        $w = ['watch_last' => $read['last'], 'watch_day' => $read['day'], 'dm_dirty' => $left, 'l' => $got['l'], 'watch_rec' => $got['rec'], 'dm_state' => $state] + $w;
        if (StatusSync::ends($w, $state, $indexOf, $since, $now)) {
            return new BatchStepResult($at, true, done: $this->total, total: $this->total);
        }
        if (!StatusSync::pending($w, $now)) {
            return $this->result(['u' => $next] + array_diff_key($at, [self::SPAN => true]), self::WATCHING);
        }
        // a step whose budget went before it asked or wrote anything fails as one without progress, or the
        // next ones would go round at once for ever
        return ($got['asked'] || ($done !== [])) ? $this->result($at) : new BatchStepResult($from, false);
    }

    /**
     * When the run started, watch_last and watch_from, as times. A record edited out of shape is set right
     * first - a step would otherwise go on at once for ever (StatusSync::pending()), or never end: a
     * watch_last that is no time, or one to come, reads from the start of the run; such a watch_from
     * follows from now - an end that came early would lose deliveries, one that comes late costs calls -,
     * and dm_dirty loses the indexes of no message sent. A start that is no time is 0: what ISDS keeps is
     * read, and no time it tells is too early. A watch_rec that is no time is none: the list is read once
     * more.
     * @param array<int, string> $byIndex index => dmID of each message sent
     * @return ?array{0: int, 1: int, 2: int, 3: ?int} started, watch_last, watch_from, watch_rec; null =
     *                                                  Continue or another step took the run
     */
    private function watchTimes(array $byIndex, int $now): ?array
    {
        $started = self::recordedTime((string) $this->started, $now) ?? 0;
        $last    = self::recordedTime((string) $this->watch_last, $now);
        $since   = self::recordedTime((string) $this->watch_from, $now);
        $dirty   = SendMaps::entries((string) $this->dm_dirty);
        $fix     = [];
        if (is_null($last)) {
            $last              = $started;
            $fix['watch_last'] = (string) $last;
        }
        if (is_null($since)) {
            $since             = $now;
            $fix['watch_from'] = (string) $since;
        }
        if (array_diff_key($dirty, $byIndex) !== []) {
            $fix['dm_dirty'] = self::json(array_intersect_key($dirty, $byIndex));
        }
        return (($fix === []) || !is_null($this->record($fix))) ? [$started, $last, $since, self::recordedTime((string) $this->watch_rec, $now)] : null;
    }

    /**
     * The state changes of a round while the budget lasts, MAX_CALLS calls at most (StatusSync::nextCall()),
     * then the list of sent messages when it is due (StatusSync::listCursor()), a page a call, merged after
     * each call: an answer tells of every message of the box, and only this sending's are kept. A page read
     * again after a failure merges the same. An answer too large halves the span of the calls, down to
     * ROUND; ISDS that does not answer ends the reading, and what was read stays read - so do a
     * datovka_conf.php PHP cannot read now and the address of the server blocked: each passes by itself, no
     * person has to act. Past StatusSync::over() nothing is read.
     * @param array<array-key, mixed>                           $state dm_state
     * @param array{last: int, day: string, at: int, span: int} $read  as StatusSync::nextCall()
     * @param array{started: int, watch_from: int, watch_rec: ?int, indexOf: array<array-key, int>, l: mixed} $w
     *        the times of the record, the messages sent, and l of the position: the reading of the list a step before left
     * @return array{state: array<array-key, mixed>, changed: array<int, int>, read: array{last: int, day: string, at: int, span: int}, rec: ?int, l: ?array<string, mixed>, asked: bool, failed: ?string}
     *         dm_state and the indexes whose entry changed, where the reading stands, when the list was read last, the
     *         reading of the list to go on with, whether ISDS was asked, and why the reading failed: '' ISDS did not
     *         answer, too_large, blocked, config; null = it did not
     * @throws BatchFailed what needs a person: the account changed, the login refused, a configuration that cannot work
     */
    private function readChanges(array $state, array $read, array $w, BatchBudget $budget, int $now): array
    {
        $changed  = [];
        $client   = null;
        $failed   = null;
        $rec      = $w['watch_rec'];
        // a reading a step before left is checked once, against the record: the windows this step reads move
        // watch_last past where it started
        $l        = is_null($w['l']) ? null : StatusSync::listCursor($w['l'], ['watch_last' => $read['last'], 'dm_state' => $state] + $w, $now);
        $merge    = static function (array $events) use (&$state, &$changed, $w, $now): void {
            $merged   = StatusSync::merge($state, $events, $w['indexOf'], $w['started'], $now);
            $state    = $merged['state'];
            $changed += array_fill_keys($merged['changed'], 1);
        };
        $over = StatusSync::over($w['watch_from'], $now);
        for ($calls = 0; !$over && ($calls < StatusSync::MAX_CALLS) && is_null($failed) && !$budget->spent(); ++$calls) {
            $call   = StatusSync::nextCall($read, $now);
            $cursor = is_null($call) ? ($l ?? StatusSync::listCursor(null, ['watch_last' => $read['last'], 'watch_rec' => $rec, 'dm_state' => $state] + $w, $now)) : null;
            if (is_null($call) && is_null($cursor)) {
                break;
            }
            try {
                $client ??= $this->checkedClient();
                if (is_string($client)) {
                    // ISDS did not say whose the login is, or PHP cannot read datovka_conf.php now
                    $failed = ($client === Outcome::CONFIG) ? 'config' : '';
                    break;
                }
                $answer = is_null($cursor) ? self::ask(static function () use ($client, $call): array|false {
                    try {
                        return $client->stateChanges($call['from'], $call['to']);
                    } catch (TransportException $e) {
                        if ($e->tooLarge()) {
                            return false;
                        }
                        throw $e;
                    }
                }) : self::ask(static fn(): array => SentList::read($client->sentMessages(...), $cursor, static function (array $records, array $cursor) use ($merge): array {
                    $merge($records);
                    return $cursor;
                }, static fn(): bool => false));
            } catch (BatchFailed $e) {
                // LoginLatch lifts the block by itself; a new password would not
                if ($e->reason !== Outcome::BLOCKED) {
                    throw $e;
                }
                $failed = 'blocked';
                break;
            }
            if (is_null($answer)) {
                $failed = '';
            } elseif (!is_null($cursor)) {
                // read to its end or given up, the reading is done: what the list did not tell, it never will
                [$read['last'], $rec, $l] = ($answer[1] === '') ? [$read['last'], $rec, $answer[0]] : [StatusSync::afterList($read['last'], (int) $cursor['h'], $now), $now, null];
            } elseif ($answer === false) {
                // more changes than an answer holds: a shorter call may fit
                $span         = StatusSync::halve(min($read['span'], $call['to'] - $call['from']));
                $failed       = is_null($span) ? 'too_large' : null;
                $read['span'] = $span ?? $read['span'];
            } else {
                $merge($answer);
                $read = $call['read'];
            }
        }
        return ['state' => $state, 'changed' => $changed, 'read' => $read, 'rec' => $rec, 'l' => $l, 'asked' => $calls > 0, 'failed' => $failed];
    }

    /**
     * The message items of $keys show the delivery $state keeps, CHUNK of them in a transaction while the
     * budget lasts. An entry out of shape, an index with no item of this campaign or one whose item is
     * gone has nothing to show it by: it is passed over, and a merge that changes its entry adds it to
     * dm_dirty again.
     * @param list<int>               $keys
     * @param array<array-key, mixed> $state   dm_state
     * @param array<int, string>      $byIndex index => dmID of each message sent
     * @param array<int, mixed>       $done    what showDelivery() gave before
     * @return array<int, mixed> $done and index => the entry its item shows now, or the value passed over
     */
    private function showDelivery(array $keys, array $state, array $byIndex, BatchBudget $budget, array $done = []): array
    {
        if (($keys === []) || $budget->spent()) {
            return $done;
        }
        $this->quiet = false;
        $rows        = $this->rows ??= $this->snapshot(false)->recipients();
        $items       = $this->items();
        $this->inChunks($keys, 0, $budget, static function (int $i, int $k) use ($rows, $items, $state, $byIndex, &$done): void {
            $done[$k] = self::showEntry($items, $rows, $state, $byIndex, $k);
        });
        return $done;
    }

    /**
     * index => dmID of each message sent
     * @return array<int, string>
     * @throws \JsonException dm_ids edited into no JSON
     */
    private function byIndex(): array
    {
        return array_map(strval(...), array_flip(StatusSync::indexOf(SendMaps::entries((string) $this->dm_ids))));
    }

    /**
     * The message item of $k shows the delivery $state keeps
     * @param list<array<string, string>> $rows
     * @param array<array-key, mixed>     $state
     * @param array<int, string>          $byIndex
     * @return mixed the entry the item shows now, or the value passed over
     */
    private static function showEntry(SendItems $items, array $rows, array $state, array $byIndex, int $k): mixed
    {
        $entry = is_string($state[$k] ?? null) ? StatusSync::entry($state[$k]) : null;
        $shown = (is_null($entry) || !isset($rows[$k])) ? null : $items->markDelivery($rows[$k]['msg'], $entry, $byIndex[$k]);
        return is_null($shown) ? ($state[$k] ?? null) : StatusSync::encode($shown);
    }

    /**
     * dm_dirty without the items that show what the record keeps, written by the record as it is now: an
     * item written before its entry changed stays to be written again
     * @param array<array-key, mixed> $dirty
     * @param array<array-key, mixed> $state
     * @param array<int, mixed>       $done  as showDelivery()
     * @return array<array-key, mixed> what is left to write
     */
    private function written(array $dirty, array $state, array $done): array
    {
        $left = self::stillDirty($dirty, $state, $done);
        if (count($left) < count($dirty)) {
            $this->recordResult(static function (self $run) use ($done): bool {
                $run->dm_dirty = self::json(self::stillDirty(SendMaps::entries((string) $run->dm_dirty), SendMaps::entries((string) $run->dm_state), $done));
                return true;
            });
        }
        return $left;
    }

    /**
     * $dirty without the indexes whose item shows the entry $state keeps, or was passed over at it
     * @param array<array-key, mixed> $dirty
     * @param array<array-key, mixed> $state
     * @param array<int, mixed>       $done  as showDelivery()
     * @return array<array-key, mixed>
     */
    private static function stillDirty(array $dirty, array $state, array $done): array
    {
        return array_filter($dirty, static fn(int|string $k): bool => !array_key_exists($k, $done) || ($done[$k] !== ($state[$k] ?? null)), ARRAY_FILTER_USE_KEY);
    }

    /** the span of calls a round shortened to after an answer too large; MAX_SPAN when none, or edited out of range */
    private static function span(array $at): int
    {
        $span = $at[self::SPAN] ?? null;
        return (is_int($span) && ($span >= StatusSync::ROUND) && ($span < StatusSync::MAX_SPAN)) ? $span : StatusSync::MAX_SPAN;
    }

    /**
     * A time the record keeps; null when it is none - edited out of shape or into the future, it must not
     * pass for a huge, negative or coming time. SKEW leaves room for another server whose clock is ahead.
     */
    private static function recordedTime(string $value, int $now): ?int
    {
        return ((preg_match('/^\d{1,10}\z/', $value) === 1) && ((int) $value <= $now + Lookup::SKEW)) ? (int) $value : null;
    }

    /** a map of the record, by index; '' when empty, as a record starts */
    private static function json(array $map): string
    {
        ksort($map);
        return ($map === []) ? '' : json_encode($map, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    /**
     * @param array<string, string> $props own properties => their text
     * @return ?bool as recordResult()
     */
    private function record(array $props): ?bool
    {
        return $this->recordResult(static function (self $run) use ($props): bool {
            foreach ($props as $property => $value) {
                $run->$property = $value;
            }
            return true;
        });
    }

    /**
     * A step run before its pause is over - Execute in the queue manager - asks ISDS nothing and waits
     * out the rest; it counts itself, as a step that leaves the position as it was is a failure
     * @return ?BatchStepResult null = the pause is over
     */
    private function early(array $from): ?BatchStepResult
    {
        if (SendPace::pauseEnd($from, static::now()) <= static::now()) {
            return null;
        }
        $code   = BatchFailed::isCode((string) $this->retry_code) ? (string) $this->retry_code : Outcome::UNAVAILABLE;
        $detail = BatchFailed::isDetail((string) $this->error_detail) ? (string) $this->error_detail : '';
        return $this->result(['e' => (int) ($from['e'] ?? 0) + 1] + $from, $code, $detail);
    }

    /**
     * The end: the campaign done, or stopped when a stop left messages unsent - one that came when
     * all had gone stopped nothing. A sending that followed delivery showed its end at the end of look,
     * and its campaign keeps it, with the time it finished. A failure here makes the core try again later.
     * @return array<string, int> the report: what became of the recipients, how many messages were
     *                            delivered and accepted
     */
    protected function finalCheck(): array
    {
        $this->requireSliceRules();
        return $this->withSlices(function (): array {
            $maps   = $this->maps();
            $report = $maps->tally($this->snapshot(false)->recipients()) + $maps->delivery();
            $this->campaignIs(self::endState($report), '', $this->finishedAt(static::now()), true);
            return $report;
        });
    }

    /**
     * A run given up, after its end committed: its items as a stop leaves them, those that may have
     * gone - in flight too - uncertain for a person to verify, and the campaign as finalCheck() ends
     * it, unless a newer run of it owns it. Each write by itself: one item that fails keeps no other
     * as it was. It comes from the queue, maybe more than once, so it writes nothing twice.
     */
    protected function afterClose(string $state): void
    {
        if ($state !== static::states()->ended) {
            return;
        }
        $this->requireSliceRules();
        try {
            $this->withSlices(function (): void {
                $rows   = $this->snapshot(false)->recipients();
                $items  = $this->items();
                $maps   = $this->maps();
                $known  = $maps->decided();
                $now    = static::now();
                $writes = [];
                foreach (array_keys($maps->unsettled()) as $k) {
                    $writes[] = fn() => $items->mark($rows[$k]['msg'], Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, $now, once: true);
                }
                foreach (array_slice($rows, (int) $this->cursor, null, true) as $k => $row) {
                    $writes[] = fn() => $this->cancel($k, $row, $items, $known);
                }
                if ((string) array_key_first(self::recordsOfSubject((string) $this->subject)) === (string) $this->getId()) {
                    $writes[] = fn() => $items->campaign(self::endState($maps->tally($rows)), $this->finishedAt($now), true);
                }
                // the delivery the record keeps and the items do not show yet; the record of an ended run stays
                // as it is, an item that fails stays in its dm_dirty. A map edited into no JSON fails as one
                // write: the others still run.
                try {
                    $state   = SendMaps::entries((string) $this->dm_state);
                    $byIndex = $this->byIndex();
                    foreach (array_keys(array_intersect_key(SendMaps::entries((string) $this->dm_dirty), $byIndex)) as $k) {
                        $writes[] = static fn() => self::showEntry($items, $rows, $state, $byIndex, $k);
                    }
                } catch (\JsonException $e) {
                    $writes[] = static fn() => throw $e;
                }
                $failure = null;
                foreach ($writes as $write) {
                    try {
                        $write();
                    } catch (\Throwable $e) {
                        $failure ??= $e;
                    }
                }
                if ($failure) {
                    throw $failure;
                }
            });
        } finally {
            $this->invalidate();
        }
    }

    /**
     * The time the sending finished, for the campaign item; none while following delivery, whose campaign
     * shows when the sending ended, not when following does
     * @return array<string, int>
     */
    private function finishedAt(int $now): array
    {
        return $this->watching() ? [] : [Schema::CAMPAIGN_FINISHED_AT => $now];
    }

    /** @param array{stopped: int} $tally SendMaps::tally() */
    private static function endState(array $tally): string
    {
        return ($tally['stopped'] > 0) ? Schema::CAMPAIGN_STATE_STOPPED : Schema::CAMPAIGN_STATE_DONE;
    }

    /** @throws BatchFailed SETTINGS: as in work(), nothing is stored into slices that break a rule */
    private function requireSliceRules(): void
    {
        if ($this->sliceRules()->sliceProblems((string) $this->camp_slice, (string) $this->msg_slice)) {
            throw new BatchFailed(self::SETTINGS);
        }
    }

    /** the next recipient to try: past the position and the cursor both - a step that died sending left its position at that message */
    private function next(array $at): int
    {
        return max((int) ($at['i'] ?? 0), (int) $this->cursor);
    }

    /** the pause till the maintenance of ISDS ends, null = ISDS may be called now */
    private function maintenancePause(array $at): ?BatchStepResult
    {
        $end = SendPace::maintenanceEnd(static::now());
        return is_null($end) ? null : $this->result(['u' => $end] + $at, self::MAINTENANCE);
    }

    /** @throws BatchFailed $code after SendPace::LONGEST_PAUSE of pauses in a row */
    private function paused(array $at, string $code): BatchStepResult
    {
        return $this->result(SendPace::paused($at, static::now(), $code), $code);
    }

    /** the step ends at $at, with what it warns of; $detail tells why it waits */
    private function result(array $at, string $retry = '', string $detail = ''): BatchStepResult
    {
        $warnings = ($this->unchecked > 0) ? [self::UNCHECKED => $this->unchecked] : [];
        return new BatchStepResult($at, false, warnings: $warnings, done: min($this->next($at), $this->total), total: $this->total, retry: $retry, retryDetail: $detail);
    }

    /**
     * The memory a message of the files takes, before they are read: memory_limit raised when it is
     * lower - till the end of the step -, the run stopped when it cannot be
     * @throws BatchFailed MEMORY with the MB needed; Snapshot::CHANGED
     */
    private function requireMemory(): void
    {
        // as restoreMemoryLimit(): free pages PHP keeps are no need
        gc_mem_caches();
        $need  = memory_get_usage(true) + self::MEMORY_SLACK + self::MEMORY_PER_BYTE * Snapshot::filesSize((string) $this->manifest);
        $limit = $this->memoryLimit();
        if (($limit < 0) || ($limit >= $need)) {
            return;
        }
        $before = $this->raiseMemoryLimit($need);
        if (is_null($before)) {
            throw new BatchFailed(self::MEMORY, '', 'mb:' . (int) ceil($need / (1024 * 1024)));
        }
        $this->memoryBefore ??= $before;
    }

    /**
     * The campaign item shows $state, an error with its code; a state it shows already is not written
     * again. Only while the run is this step's: a step another one replaced would show its error
     * over the sending of the other.
     * @param array<string, int> $fields further fields of SendItems::CAMPAIGN_OWN
     * @param bool               $once   as SendItems::campaign(): a state the item shows is not written
     *                                   again, though the run did not record it
     */
    private function campaignIs(string $state, string $code = '', array $fields = [], bool $once = false): void
    {
        if ((($state === $this->camp_state) && ($code === '')) || !$this->ownsRun()) {
            return;
        }
        $this->items()->campaign($state, (($code === '') ? [] : [Schema::CAMPAIGN_ERROR_TEXT => $code]) + $fields, $once);
        $this->recordResult(static function (self $run) use ($state): bool {
            $run->camp_state = $state;
            return true;
        });
    }

    /**
     * $fn with the campaign and the message slice open: they have reading passwords, and an item read
     * masked would be stored masked. A slice open before stays open; the others are closed again for
     * the next task the queue runs in this process.
     */
    private function withSlices(\Closure $fn): mixed
    {
        return AA_Credentials::singleton()->withSlicesOpen([(string) $this->camp_slice, (string) $this->msg_slice], $fn);
    }

    /** @throws BatchFailed Snapshot::CHANGED */
    private function snapshot(bool $files): Snapshot
    {
        return Snapshot::read((string) $this->snapshot, (string) $this->manifest, $files, (string) $this->subject);
    }

    /**
     * The client of the account the run was queued with: datovka_conf.php still binds that account to
     * the site and the pair of slices, and its login still belongs to the box the run sends from. ISDS
     * is asked whose the login is when it changed (another password or certificate), on the first step
     * of sending, and then once an hour, once a day while following delivery - a certificate belongs to
     * one box, so the fingerprint alone tells a new sender, the question is a safeguard.
     * @return Client|string the client, or the code a pause fails with after SendPace::LONGEST_PAUSE:
     *                       Outcome::UNAVAILABLE when ISDS did not answer whose the login is,
     *                       Outcome::CONFIG when PHP could not read datovka_conf.php
     * @throws BatchFailed ACCOUNT_CHANGED, REFUSED, or Outcome's code of a login ISDS refused or a configuration that cannot work
     */
    private function checkedClient(): Client|string
    {
        $conf = $this->conf();
        if ($conf->unreadable()) {
            // a network file system refuses PHP now and then: what is set up may be back the next step
            return Outcome::CONFIG;
        }
        [$site, $campaignSlice, $messageSlice, $env] = [(string) $this->site, (string) $this->camp_slice, (string) $this->msg_slice, (string) $this->env];
        [$name, $kind] = [(string) $this->account, (string) $this->kind];
        // the file, or the account of the run in it, is fixed by hand - no sender went away; an account
        // set to null is one taken away
        if ($conf->broken() || !is_array($conf->accounts()[$name] ?? [])) {
            throw new BatchFailed(Outcome::CONFIG);
        }
        // no file is a file without accounts: the run ends at once, as an installation without the file
        // never gets one by waiting - an update that moves the file aside ends the run too
        try {
            $binding = Accounts::binding($site, $campaignSlice, $messageSlice, $env, $conf);
        } catch (ConfigException) {
            // the account of the run, still bound to the site and the slices as far as they can be read, only
            // broken, is fixed in the file - no sender went away
            $broken = array_filter(Accounts::bindings($conf), static fn(Binding $b): bool => ($b->name === $name) && ($b->kind === $kind) && ($b->environment === $env)
                && (($b->sites === []) || in_array($site, $b->sites, true))
                && (($b->campaignSlice === '') || (($b->campaignSlice === $campaignSlice) && ($b->messageSlice === $messageSlice))));
            throw new BatchFailed($broken ? Outcome::CONFIG : self::ACCOUNT_CHANGED);
        }
        if (($binding->name !== $name) || ($binding->kind !== $kind) || ($binding->environment !== $env)) {
            throw new BatchFailed(self::ACCOUNT_CHANGED);
        }
        try {
            $account     = Accounts::account($binding, $conf);
            $fingerprint = self::fingerprintOf($account);
        } catch (ConfigException $e) {
            throw new BatchFailed(Outcome::of($e)->code);
        }
        $client = $this->client($account);
        $now    = static::now();
        $every  = $this->watching() ? self::OWNER_WATCHING : self::OWNER_EVERY;
        if (($fingerprint === $this->acct_id) && ((string) $this->owner_at !== '') && ($now - (int) $this->owner_at < $every)) {
            return $client;
        }
        $box = self::ask($client->ownerBox(...));
        if (is_null($box)) {
            return Outcome::UNAVAILABLE;
        }
        if ($box !== $this->sender_box) {
            throw new BatchFailed(self::ACCOUNT_CHANGED);
        }
        $this->recordResult(static function (self $run) use ($fingerprint, $now): bool {
            $run->acct_id  = $fingerprint;
            $run->owner_at = (string) $now;
            return true;
        });
        return $client;
    }

    /**
     * $ask of ISDS - a question, nothing sent - its failures as the run takes them; queueing a run
     * asks whose the login is through it too, so both refuse alike
     * @template T
     * @param \Closure(): T $ask
     * @return ?T null = ISDS did not answer: the run pauses
     * @throws BatchFailed Outcome's code of a login ISDS refused or a configuration that cannot work;
     *                     REFUSED with the status of any other refusal
     */
    public static function ask(\Closure $ask): mixed
    {
        try {
            return $ask();
        } catch (TransportException|UnavailableException) {
            return null;
        } catch (AuthException|ConfigException $e) {
            throw new BatchFailed(Outcome::of($e)->code);
        } catch (IsdsException $e) {
            // the status ISDS refused with, never its words
            throw new BatchFailed(self::REFUSED, '', SendPace::refusalDetail($e->isdsCode()));
        }
    }

    // ------------------------------------------------------------ what tests replace

    /** @return bool got it - unlockCampaign() then; false also when the database gives no lock at all */
    protected static function lockCampaign(string $campaign): bool
    {
        return DB_AA::lock(self::lockName($campaign));
    }

    protected static function unlockCampaign(string $campaign): void
    {
        DB_AA::unlock(self::lockName($campaign));
    }

    /** the settings of the installation, read once a request */
    protected function conf(): DatovkaConf
    {
        return DatovkaConf::load();
    }

    protected function client(Account $account): Client
    {
        return new Client($account);
    }

    protected function sliceRules(): SliceRules
    {
        return new SliceRules();
    }

    protected function items(): SendItems
    {
        return new SendItems((string) $this->camp_slice, (string) $this->msg_slice, (string) $this->subject);
    }

    /** memory_limit in bytes, -1 = none */
    protected function memoryLimit(): int
    {
        return ini_parse_quantity((string) ini_get('memory_limit'));
    }

    /** @return ?string memory_limit before it is $bytes; null = the server does not let a script raise it */
    protected function raiseMemoryLimit(int $bytes): ?string
    {
        $before = ini_set('memory_limit', (string) $bytes);
        return ($before === false) ? null : $before;
    }

    /** memory_limit back to $limit - unless the process holds more already, which PHP refuses with a
     *  warning: it compares with its whole heap, free pages too, more than memory_get_usage() says */
    protected function restoreMemoryLimit(string $limit): void
    {
        $bytes = ini_parse_quantity($limit);
        gc_mem_caches();
        if (($bytes < 0) || ($bytes >= memory_get_usage(true))) {
            ini_set('memory_limit', $limit);
        }
    }

    /** the pages of the message slice, once a step: SendItems stores message items without it */
    protected function invalidate(): void
    {
        AA::Pagecache()->invalidateFor((string) $this->msg_slice);
    }
}
