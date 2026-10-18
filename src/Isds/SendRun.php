<?php

declare(strict_types=1);

namespace AA\Isds;

use AA;
use AA\Credential\Store;
use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\BatchStates;
use AA\Later\BatchStepResult;
use AA\Util\Secret;
use AA_Credentials;
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
 * A pause waits for ISDS without spending the run's retries: w pauses in a row, u the time to wait
 * till, s the start of the first one (SendPace).
 */
class SendRun extends BatchRun
{
    /** BatchFailed reason: config.php no longer binds the account the run was queued with, or its login now belongs to another box */
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
    /** stop(), resumeFor(): another request holds the lock of the campaign */
    public const BUSY = 'BUSY';
    /** stop(): every message has been tried, nothing is left to stop */
    public const ALL_SENT = 'ALL_SENT';
    /** stop() of a run in error with nothing left to stop: given up, its note */
    public const ABANDONED = 'ABANDONED';
    /** the error BatchRun gives a failure it does not know */
    private const INTERNAL = 'INTERNAL';

    private const PREP = 'prep';
    private const SEND = 'send';
    private const STOP = 'stop';
    private const LOOK = 'look';

    /** the run's own properties, each a text row of the record */
    private const OWN = [
        'site', 'env', 'account', 'kind', 'acct_id', 'sender_box', 'camp_slice', 'msg_slice', 'camp_short',
        'snapshot', 'manifest', 'cursor', 'inflight', 'inflight_at', 'dm_ids', 'uncertain', 'failed', 'skips',
        'streak', 'camp_state', 'owner_at', 'stop_by',
    ];

    /** message items in one transaction: a commit each would cost a sync of the disk each */
    private const CHUNK = 50;

    /** the key of Account::fingerprint() in the record - a fingerprint of another purpose does not fit it */
    private const FINGERPRINT = 'isds-send';

    /** seconds between checks of the box the login belongs to, when its fingerprint did not change */
    private const OWNER_EVERY = 3600;

    /** seconds a step must have left to start a message; the message may take longer, the lease covers it (overrun()) */
    private const MIN_SEND = 3;
    /** the position of a pause: w pauses in a row, u the time to wait till, s the start of the first, e the steps run before u */
    private const PAUSE_KEYS = ['w', 'u', 's', 'e'];
    /** the position of a reading of the list a look did not finish (Lookup::$scan) */
    private const READING = 'l';

    /** memory of a message beyond what the process holds: the files read from the database (its buffer
     *  of their base64 too), the base64 of the request and the request - a few times the files */
    private const MEMORY_PER_BYTE = 8;
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

    /** messages of this step sent without the state of their box */
    private int $unchecked = 0;
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
     * dead) goes on at once. A run in error that is stopped already, or has nothing left to stop, is
     * given up: what may have gone stays uncertain for a person to verify.
     * @return string '' = stopping; ABANDONED; or why not: BUSY, FORBIDDEN, WRONG_STATE (no sending, or
     *                one that ended), ALL_SENT
     */
    public static function stop(string $campaign, string $uid): string
    {
        return self::underLock($campaign, $uid, static function (self $run) use ($uid): string {
            $states = static::states();
            if (in_array($run->state(), [$states->done, $states->ended], true)) {
                return 'WRONG_STATE';
            }
            $stopped = ((string) $run->stop_by !== '');
            // a sending all sent does not end as stopped
            $allSent = !$stopped && $run->triedAll();
            if (($run->state() === $states->error) && ($stopped || $allSent)) {
                $run->end($uid, self::ABANDONED);
                return self::ABANDONED;
            }
            if ($allSent) {
                return self::ALL_SENT;
            }
            if (!$stopped) {
                $run->stop_by = $uid;
                $run->update(['stop_by']);
            }
            if ($run->mayResume(static::now())) {
                $run->resume($uid);
            }
            return '';
        });
    }

    /**
     * Every message tried: the cursor past the last recipient. A manifest that does not say how many
     * there are tells nothing, and the stop goes on - its steps fail on it, and a second stop gives
     * the run up.
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

    /** the name of the DB_AA::lock() of a campaign: queueing, stopping and Continue take it, one at a time */
    public static function lockName(string $campaign): string
    {
        return 'isds-campaign:' . strtolower($campaign);
    }

    /**
     * $do with the run of $campaign locked, under the lock of the campaign - released after the
     * commit, so whoever waits for it sees what $do wrote. Only for $uid that may control the run,
     * asked before the locks too: no one else holds them up.
     * @param \Closure(self): string $do
     * @return string what $do says; BUSY, or the code it was refused with
     */
    private static function underLock(string $campaign, string $uid, \Closure $do): string
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('A sending is stopped or continued outside any transaction');
        }
        $campaign = strtolower($campaign);
        $id       = (preg_match('/^[0-9a-f]{32}\z/', $campaign) === 1) ? array_key_first(self::recordsOfSubject($campaign)) : null;
        if (is_null($id)) {
            return 'WRONG_STATE';
        }
        if (!(static::get((string) $id)?->mayControl($uid) ?? false)) {
            return 'FORBIDDEN';
        }
        if (!static::lockCampaign($campaign)) {
            return self::BUSY;
        }
        try {
            return DB_AA::transaction(static function () use ($id, $uid, $do): string {
                $run = static::lockForUpdate((string) $id) ?? throw new BatchFailed('WRONG_STATE');
                return $run->mayControl($uid) ? $do($run) : 'FORBIDDEN';
            });
        } catch (BatchFailed $e) {
            return $e->reason;
        } finally {
            static::unlockCampaign($campaign);
        }
    }

    protected static function states(): BatchStates
    {
        return new BatchStates('sending', 'error', 'done', 'stopped');
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
     * prep and stop call no ISDS: a step of theirs that died holds Continue back no longer than one of
     * any run.
     */
    protected function overrun(): int
    {
        return in_array($this->position()['p'] ?? self::PREP, [self::SEND, self::LOOK], true) ? Client::TIMEOUT + Client::CREATE_TIMEOUT + SendPace::RECORD_RESERVE + 10 : parent::overrun();
    }

    /** after a pause till its end; after a failed step a minute more for each failure */
    protected function retryDelay(int $failures): int
    {
        return ($failures === 0) ? max(1, SendPace::pauseEnd($this->position(), static::now()) - static::now()) : 60 * $failures;
    }

    /** Continue gives a pause the full time again, and reads the list from its start: a reading a step
     *  failed on would fail again */
    protected function positionOnResume(array $position): array
    {
        return array_diff_key($position, array_flip([...self::PAUSE_KEYS, self::READING]));
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
        $failure = null;
        try {
            $inflight = (string) $this->inflight;
            if (($inflight !== '') && !$this->settleInflight()) {
                // ended, or Continue or another step took the run: the core drops this result
                return new BatchStepResult($from, false);
            }
            // the recipients only: the attachments are read when sending, past the account
            $snapshot = $this->snapshot(false);
            $msg      = ($inflight === '') ? null : ($snapshot->recipients()[(int) $inflight]['msg'] ?? null);
            if (!is_null($msg)) {
                $this->items()->mark($msg, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, static::now());
            }
            $phase = (string) ($from['p'] ?? self::PREP);
            if (!in_array($phase, [self::PREP, self::SEND, self::STOP, self::LOOK], true)) {
                throw new \LogicException("A sending has no phase $phase");
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
            $this->campaignFails($failure);
            throw $failure;
        } finally {
            try {
                $this->invalidate();
            } catch (\Throwable $e) {
                // the failure of the step is the one the run shows
                if (is_null($failure)) {
                    $this->campaignFails($e);
                    throw $e;
                }
            }
        }
    }

    /** the run ends in error, so the campaign must not go on showing it sending - the code only, as the run's */
    private function campaignFails(\Throwable $e): void
    {
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
        if (is_null($client)) {
            return $this->paused($from, Outcome::UNAVAILABLE);
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
        $message = new Message($row['box'], $letter->annotation, $letter->files, $this->camp_short . '-' . ($k + 1), $letter->toHands);
        $now     = static::now();
        $stopped = false;
        $going   = $this->beforeAction(static function (self $run) use ($k, $now, &$stopped): bool {
            // stopped meanwhile, a message on its way already, or one the record has the fate of - also
            // below a cursor lowered by hand
            $stopped = ((string) $run->stop_by !== '');
            if ($stopped || ((string) $run->inflight !== '') || ($k < (int) $run->cursor) || isset($run->maps()->decided()[$k])) {
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

    /** what the record keeps of each recipient, as this instance read or wrote it */
    private function maps(): SendMaps
    {
        return new SendMaps((string) $this->dm_ids, (string) $this->uncertain, (string) $this->failed, (string) $this->skips, (string) $this->inflight, (int) $this->inflight_at);
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
     * @param list<array<string, string>>                    $rows
     * @param \Closure(int, array<string, string>): void $write
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
        if (isset($known[$k])) {
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
            return new BatchStepResult($from, true, done: $this->total, total: $this->total);
        }
        $early = $this->early($from) ?? $this->maintenancePause($from);
        if ($early) {
            return $early;
        }
        $client = $this->checkedClient();
        if (is_null($client)) {
            return $this->paused($from, Outcome::UNAVAILABLE);
        }
        $wanted = [];
        foreach ($open as $k => $triedAt) {
            $wanted[$k] = ['ref' => $this->camp_short . '-' . ($k + 1), 'box' => $rows[$k]['box'], 'at' => $triedAt];
        }
        // a further page only while the budget lasts: overrun() covers the last one
        $lookup = $this->ask(fn(): Lookup => Lookup::resolve($client->sentMessages(...), $wanted, $snapshot->annotation(), static::now(), (array) ($from[self::READING] ?? []), static fn(): bool => !$budget->spent()));
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
        return ($lookup->until() > 0) ? $this->result(['u' => $lookup->until()] + $at, self::LOOKING) : new BatchStepResult($at, true, done: $this->total, total: $this->total);
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
        $code = BatchFailed::isCode((string) $this->retry_code) ? (string) $this->retry_code : Outcome::UNAVAILABLE;
        return $this->result(['e' => (int) ($from['e'] ?? 0) + 1] + $from, $code);
    }

    /**
     * The end: the campaign done, or stopped when a stop left messages unsent - one that came when
     * all had gone stopped nothing. A failure here makes the core try again later.
     * @return array<string, int> the report: what became of the recipients
     */
    protected function finalCheck(): array
    {
        $this->requireSliceRules();
        return $this->withSlices(function (): array {
            $report = $this->maps()->tally($this->snapshot(false)->recipients());
            $this->campaignIs(self::endState($report), '', [Schema::CAMPAIGN_FINISHED_AT => static::now()], true);
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
                    $writes[] = fn() => $items->campaign(self::endState($maps->tally($rows)), [Schema::CAMPAIGN_FINISHED_AT => $now], true);
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

    /** the step ends at $at, with what it warns of */
    private function result(array $at, string $retry = ''): BatchStepResult
    {
        $warnings = ($this->unchecked > 0) ? [self::UNCHECKED => $this->unchecked] : [];
        return new BatchStepResult($at, false, warnings: $warnings, done: min($this->next($at), $this->total), total: $this->total, retry: $retry);
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
     * The client of the account the run was queued with: config.php still binds that account to the
     * site and the pair of slices, and its login still belongs to the box the run sends from. ISDS is
     * asked whose the login is when it changed (another password or certificate), on the first step
     * of sending, and then once an hour - a certificate belongs to one box, so the fingerprint alone
     * tells a new sender, the question is a safeguard.
     * @return ?Client null = ISDS did not answer whose the login is
     * @throws BatchFailed ACCOUNT_CHANGED, REFUSED, or Outcome's code of a login ISDS refused or a configuration that cannot work
     */
    private function checkedClient(): ?Client
    {
        $constants = $this->constants();
        [$site, $campaignSlice, $messageSlice, $env] = [(string) $this->site, (string) $this->camp_slice, (string) $this->msg_slice, (string) $this->env];
        [$name, $kind] = [(string) $this->account, (string) $this->kind];
        try {
            $binding = Accounts::binding($site, $campaignSlice, $messageSlice, $env, $constants);
        } catch (ConfigException) {
            // the account of the run, still bound to the slices, only broken, is fixed in config.php - no sender went away
            $broken = array_filter(Accounts::bindings($constants), static fn(Binding $b): bool => ($b->name === $name) && ($b->kind === $kind) && ($b->environment === $env) && $b->binds($site, $campaignSlice, $messageSlice));
            throw new BatchFailed($broken ? Outcome::CONFIG : self::ACCOUNT_CHANGED);
        }
        if (($binding->name !== $name) || ($binding->kind !== $kind) || ($binding->environment !== $env)) {
            throw new BatchFailed(self::ACCOUNT_CHANGED);
        }
        try {
            $account     = Accounts::account($binding, $constants);
            $fingerprint = self::fingerprintOf($account);
        } catch (ConfigException $e) {
            throw new BatchFailed(Outcome::of($e)->code);
        }
        $client = $this->client($account);
        $now    = static::now();
        if (($fingerprint === $this->acct_id) && ((string) $this->owner_at !== '') && ($now - (int) $this->owner_at < self::OWNER_EVERY)) {
            return $client;
        }
        $box = $this->ask($client->ownerBox(...));
        if (is_null($box)) {
            return null;
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
     * $ask of ISDS - a question, nothing sent - its failures as the run takes them
     * @template T
     * @param \Closure(): T $ask
     * @return ?T null = ISDS did not answer: the run pauses
     * @throws BatchFailed Outcome's code of a login ISDS refused or a configuration that cannot work;
     *                     REFUSED with the status of any other refusal
     */
    private function ask(\Closure $ask): mixed
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

    /** @return array<string, mixed> the constants of config.php, read once a step */
    protected function constants(): array
    {
        return Store::userConstants();
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
