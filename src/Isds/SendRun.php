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
 * Phases of the position {p, i}: prep makes a message item for each frozen recipient; send checks
 * the account before ISDS is asked anything.
 * A pause waits for ISDS without spending the run's retries: w pauses in a row, u the time to wait
 * till, s the start of the first one.
 */
class SendRun extends BatchRun
{
    /** BatchFailed reason: config.php no longer binds the account the run was queued with, or its login now belongs to another box */
    public const ACCOUNT_CHANGED = 'ACCOUNT_CHANGED';
    /** BatchFailed reason: ISDS refused to say whose the login is; the detail is its status */
    public const REFUSED = 'ISDS_REFUSED';
    /** BatchFailed reason: the campaign or the message slice breaks a rule of SliceRules since the run was queued */
    public const SETTINGS = 'SETTINGS';

    private const PREP = 'prep';
    private const SEND = 'send';

    /** the run's own properties, each a text row of the record */
    private const OWN = [
        'site', 'env', 'account', 'kind', 'acct_id', 'sender_box', 'camp_slice', 'msg_slice', 'snapshot',
        'manifest', 'cursor', 'inflight', 'inflight_at', 'uncertain', 'camp_state', 'owner_at',
    ];

    /** message items in one transaction: a commit each would cost a sync of the disk each */
    private const CHUNK = 50;

    /** the key of Account::fingerprint() in the record - a fingerprint of another purpose does not fit it */
    private const FINGERPRINT = 'isds-send';

    /** seconds between checks of the box the login belongs to, when its fingerprint did not change */
    private const OWNER_EVERY = 3600;

    /** the first pause in seconds, doubled with each next one up to LONGEST_WAIT */
    private const FIRST_WAIT   = 30;
    private const LONGEST_WAIT = 600;
    /** pauses in a row that long end the run in error: ISDS is not just being maintained */
    private const LONGEST_PAUSE = 6 * 3600;

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
    /** the id of the Snapshot and its manifest */
    protected $snapshot = '';
    protected $manifest = '';
    /** The first recipient index no message was tried for; it only grows. The next message to try is
     *  max(position i, cursor): a step that died sending message k leaves cursor k+1, i still k. */
    protected $cursor = '';
    /** the index whose message may be on its way, and since when; set before the request leaves */
    protected $inflight = '';
    protected $inflight_at = '';
    /** JSON index => the time a message of unknown fate was tried: looked up, never sent again */
    protected $uncertain = '';
    /** the last state the run gave the campaign */
    protected $camp_state = '';
    /** the time ISDS last said which box the login belongs to */
    protected $owner_at = '';

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

    /** a step that waits for CreateMessage must not be taken for dead - one cut short may have sent it */
    protected function overrun(): int
    {
        return Client::CREATE_TIMEOUT + 60;
    }

    /** after a pause till its end; after a failed step a minute more for each failure */
    protected function retryDelay(int $failures): int
    {
        return ($failures === 0) ? max(1, (int) ($this->position()['u'] ?? 0) - static::now()) : 60 * $failures;
    }

    /** Continue gives a pause the full time again */
    protected function positionOnResume(array $position): array
    {
        unset($position['w'], $position['u'], $position['s']);
        return $position;
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        // a run lasts hours: a template, a feed or a password taken off meanwhile would get what the
        // open slices hold - so nothing is stored, not even the error into the campaign
        if ($this->sliceRules()->sliceProblems((string) $this->camp_slice, (string) $this->msg_slice)) {
            throw new BatchFailed(self::SETTINGS);
        }
        try {
            return $this->withSlices(fn(): BatchStepResult => $this->workFrom($from, $budget));
        } finally {
            $this->invalidate();
        }
    }

    private function workFrom(array $from, BatchBudget $budget): BatchStepResult
    {
        $inflight = (string) $this->inflight;
        if (($inflight !== '') && !$this->settleInflight()) {
            // ended, or Continue or another step took the run: the core drops this result
            return new BatchStepResult($from, false);
        }
        try {
            // the recipients only: the attachments are read when sending, past the account
            $snapshot = $this->snapshot(false);
            $msg      = ($inflight === '') ? null : ($snapshot->recipients()[(int) $inflight]['msg'] ?? null);
            if (!is_null($msg)) {
                $this->items()->mark($msg, Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, static::now());
            }
            $phase = (string) ($from['p'] ?? self::PREP);
            if (!in_array($phase, [self::PREP, self::SEND], true)) {
                throw new \LogicException("A sending has no phase $phase");
            }
            // after Continue the campaign shows the error the run stopped at; prep leaves a queued one till its end
            if (($phase === self::SEND) || ($this->camp_state === Schema::CAMPAIGN_STATE_ERROR)) {
                $this->campaignIs(Schema::CAMPAIGN_STATE_SENDING);
            }
            if ($phase === self::PREP) {
                return $this->prepare($from, $budget, $snapshot);
            }
            $client = $this->checkedClient();
            return $client ? $this->send($from, $client) : $this->pause($from, Outcome::UNAVAILABLE);
        } catch (BatchFailed $e) {
            try {
                $this->campaignIs(Schema::CAMPAIGN_STATE_ERROR, $e->reason);
            } catch (\Throwable) {
                // the run keeps its error all the same - a campaign item that cannot be written must not hide it
            }
            throw $e;
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
            $uncertain = json_decode(((string) $run->uncertain === '') ? '{}' : (string) $run->uncertain, true, 2, JSON_THROW_ON_ERROR);
            $uncertain[$index] ??= (int) $run->inflight_at;
            $run->uncertain   = json_encode($uncertain, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
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
        $at    = (int) ($from['i'] ?? 0);
        while (($at < count($rows)) && !$budget->spent()) {
            $chunk = array_slice($rows, $at, self::CHUNK);
            DB_AA::transaction(function () use ($chunk, $items): void {
                foreach ($chunk as $row) {
                    $items->create($row, (string) $this->env, (string) $this->sender_box, static::now());
                }
            });
            $at += count($chunk);
        }
        if ($at < count($rows)) {
            return new BatchStepResult(['p' => self::PREP, 'i' => $at], false, done: $at, total: count($rows));
        }
        $this->campaignIs(Schema::CAMPAIGN_STATE_SENDING);
        return new BatchStepResult(['p' => self::SEND, 'i' => 0], false, done: $at, total: count($rows));
    }

    /** Sends nothing: it reads the letter, and the step ends where it began - the run stops in error after BatchRun::MAX_FAILURES steps. */
    private function send(array $from, Client $client): BatchStepResult
    {
        $this->snapshot(true)->letter();
        return new BatchStepResult($from, false);
    }

    /**
     * The campaign item shows $state, an error with its code; a state it shows already is not written
     * again. Only while the run is this step's: a step another one replaced would show its error
     * over the sending of the other.
     */
    private function campaignIs(string $state, string $code = ''): void
    {
        if ((($state === $this->camp_state) && ($code === '')) || !$this->ownsRun()) {
            return;
        }
        $this->items()->campaign($state, ($code === '') ? [] : [Schema::CAMPAIGN_ERROR_TEXT => $code]);
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
        try {
            $box = $client->ownerBox();
        } catch (TransportException|UnavailableException) {
            return null;
        } catch (AuthException|ConfigException $e) {
            throw new BatchFailed(Outcome::of($e)->code);
        } catch (IsdsException $e) {
            // the status ISDS refused with, never its words
            throw new BatchFailed(self::REFUSED, '', (preg_match('/^\d{1,5}\z/', $e->isdsCode()) === 1) ? 'status:' . $e->isdsCode() : '');
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
     * The position moves to the end of the pause, so the pause spends none of the run's retries;
     * retryDelay() waits it out
     * @throws BatchFailed $code after LONGEST_PAUSE of pauses in a row
     */
    private function pause(array $at, string $code): BatchStepResult
    {
        $now   = static::now();
        $since = (int) ($at['s'] ?? $now);
        if ($now - $since > self::LONGEST_PAUSE) {
            throw new BatchFailed($code);
        }
        $waits = (int) ($at['w'] ?? 0);
        $until = $now + min(self::LONGEST_WAIT, self::FIRST_WAIT * 2 ** min($waits, 5));
        return new BatchStepResult(['w' => $waits + 1, 'u' => $until, 's' => $since] + $at, false, retry: $code);
    }

    // ------------------------------------------------------------ what tests replace

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

    /** the pages of the message slice, once a step: SendItems stores message items without it */
    protected function invalidate(): void
    {
        AA::Pagecache()->invalidateFor((string) $this->msg_slice);
    }
}
