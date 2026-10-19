<?php

declare(strict_types=1);

namespace AA\Isds;

use AA;
use AA\IO\DB\DB_AA;
use AA\Isds\Registry\Config;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Upload\UploadDir;
use AA_Credentials;
use AA_Items;
use AA_Log;
use AA_Slice;
use ItemContent;
use zids;

/**
 * The decision to send a campaign: the account, the slices and the campaign checked, the letter and
 * the recipients frozen, the run started and the campaign queued - in one transaction, under the lock
 * of the campaign, so that a double click or a second tab starts no second run. ISDS is asked whose
 * the login is before, outside the transaction - a refused login is logged through the same
 * connection, and a rollback would take the record away - and outside the lock, which a slow answer
 * would hold against a stop. Only the send action of {datovkabulk} calls it, after Access and the
 * form token.
 */
class SendQueue
{
    /** log.type of a sending queued, with the user and the run */
    public const LOG_EVENT = 'ISDS_SEND';

    /**
     * Memory a snapshot takes beyond the letter read - its base64 rows and a row escaped for its INSERT:
     * over a file of 1, 5 and 20 MB, Letter::of() took 1.0 times the files at its peak and with
     * Snapshot::of() 2.1, 2.7 and 2.8 times (memory_get_usage(true)).
     */
    private const MEMORY_PER_BYTE = 2;
    private const MEMORY_SLACK    = 16 * 1024 * 1024;

    /**
     * @param Setup  $setup     the settings of the page that sends, as its gate checked them in this request
     * @param string $campaign  long id of the campaign item
     * @param string $uid       the user logged in to the request (AuthGetUid()), never a value the request
     *                          carries: FORBIDDEN asks the rights of this uid, and the run is theirs
     * @param int    $confirmed the recipients the user confirmed - the preview counts them alike (Gate::recipients())
     * @throws \Throwable a failure no code tells, the text of which is no user's to see: one before the
     *                    commit leaves nothing written; one of the log after it leaves the run queued
     */
    public function queue(Setup $setup, string $campaign, string $uid, int $confirmed): SendStart
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('A sending is queued outside any transaction');
        }
        $campaign = ModuleId::normalize($campaign);
        if (is_null($campaign)) {
            return SendStart::refused(SendStart::UNKNOWN);
        }
        // BatchRun::start() checks no rights: the run is the user's to stop and continue
        if (!Access::userMay($uid, $setup->campaignSlice)) {
            return SendStart::refused(SendStart::FORBIDDEN);
        }
        $ready = $this->ready($setup);
        if ($ready instanceof SendStart) {
            return $ready;
        }
        [$binding, $account, $config] = $ready;
        // the gate opened it for the page; queued from elsewhere, it would read the letter masked
        return AA_Credentials::singleton()->withSlicesOpen([$setup->campaignSlice], function () use ($setup, $campaign, $uid, $confirmed, $binding, $account, $config): SendStart {
            // a campaign that cannot go is refused before ISDS: each login there counts
            $draft = $this->draft($setup, $campaign);
            if ($draft instanceof SendStart) {
                return $draft;
            }
            $props = $this->sender($setup, $binding, $account);
            if ($props instanceof SendStart) {
                return $props;
            }
            if (!$this->lock($campaign)) {
                return SendStart::refused(SendStart::BUSY);
            }
            try {
                return $this->freeze($setup, $campaign, $uid, $confirmed, $config, $props);
            } finally {
                $this->unlock($campaign);
            }
        });
    }

    /**
     * The account bound to the site and the two slices, and the rules of the slices and the source -
     * as the page checked them, once more: nothing else stands between a request and a run
     * @return array{Binding, Account, Config}|SendStart
     */
    private function ready(Setup $setup): array|SendStart
    {
        $conf = $this->conf();
        if ($conf->unreadable() || $conf->broken()) {
            // as Setup: of a file AA cannot use, only that
            return SendStart::refused(SendStart::SETTINGS);
        }
        try {
            $binding = Accounts::binding($setup->site, $setup->campaignSlice, $setup->messageSlice, $setup->environment, $conf);
            $account = Accounts::account($binding, $conf);
        } catch (ConfigException $e) {
            return SendStart::refused(SendStart::ACCOUNT, $e->getMessage());
        }
        $rules    = $this->rules();
        $problems = [
            ...$rules->siteProblems($setup->site, $setup->campaignSlice, $setup->messageSlice),
            ...$rules->sliceProblems($setup->campaignSlice, $setup->messageSlice),
            ...$rules->sourceProblems($setup->site, $setup->sourceSlice, array_map('strval', array_keys($setup->types)), $setup->memberField),
        ];
        // Recipients::resolve() refuses settings without a type field; sourceProblems() read its own copy
        $config = $rules->registryConfig($setup->sourceSlice);
        if ($problems || is_null($config) || ($config->typeField() === '')) {
            return SendStart::refused(SendStart::SETTINGS, implode('; ', array_map(static fn(SliceProblem $p): string => $p->text, $problems)));
        }
        return [$binding, $account, $config];
    }

    /**
     * The campaign as stored now, when it may be sent: an active item of the page's campaign slice, a
     * draft, never sent - the item cache of the request may hold it as it was before another request
     * queued it
     * @return ItemContent|SendStart refused UNKNOWN, NOT_DRAFT or ALREADY
     */
    private function draft(Setup $setup, string $campaign): ItemContent|SendStart
    {
        $item = $this->campaign($campaign);
        if (is_null($item) || (strtolower($item->getSliceID()) !== $setup->campaignSlice) || !$item->isActive()) {
            return SendStart::refused(SendStart::UNKNOWN);
        }
        if ((string) $item->getValue(Schema::CAMPAIGN_STATE) !== Schema::CAMPAIGN_STATE_DRAFT) {
            return SendStart::refused(SendStart::NOT_DRAFT);
        }
        return $this->runClass()::existsFor($campaign) ? SendStart::refused(SendStart::ALREADY) : $item;
    }

    /**
     * Whose the login is, as ISDS says it, and what the run keeps of the account
     * @return array<string, string>|SendStart the run's own properties of the account and the slices
     */
    private function sender(Setup $setup, Binding $binding, Account $account): array|SendStart
    {
        try {
            [$fingerprint, $box] = SendRun::ask(fn(): array => [SendRun::fingerprintOf($account), $this->client($account)->ownerBox()]) ?? throw new BatchFailed(Outcome::UNAVAILABLE);
        } catch (BatchFailed $e) {
            return self::refusedBy($e);
        }
        // a hosted filing service sends from the box the page names: a login of another box would stop the run at its first message
        if (($binding->kind === Account::HOSTED) && ($box !== $setup->senderBox)) {
            return SendStart::refused(SendStart::ACCOUNT);
        }
        return [
            'site'       => $setup->site,
            'env'        => $binding->environment,
            'account'    => $binding->name,
            'kind'       => $binding->kind,
            'acct_id'    => $fingerprint,
            'sender_box' => $box,
            'camp_slice' => $setup->campaignSlice,
            'msg_slice'  => $setup->messageSlice,
        ];
    }

    /**
     * Under the lock: the campaign checked again, its recipients and its letter frozen, then the run
     * @param array<string, string> $props the run's own properties of the account
     */
    private function freeze(Setup $setup, string $campaign, string $uid, int $confirmed, Config $config, array $props): SendStart
    {
        // another request may have queued it while ISDS answered
        $item = $this->draft($setup, $campaign);
        if ($item instanceof SendStart) {
            return $item;
        }
        $recipients = $this->recipients($setup, $item, $config, $confirmed);
        if ($recipients instanceof SendStart) {
            return $recipients;
        }
        $snapshot = $this->snapshot($setup, $item, $recipients, $campaign);
        if ($snapshot instanceof SendStart) {
            return $snapshot;
        }
        // the count confirmed is the count of those who get the letter now
        return $this->start($setup, $campaign, $uid, $snapshot, $confirmed, $props + ['camp_short' => (string) $item->getValue('short_id........')]);
    }

    /**
     * Whom by the stored filter - by the function the preview counts with, so that the count the user
     * confirmed is the count frozen
     * @return list<Recipient>|SendStart
     */
    private function recipients(Setup $setup, ItemContent $item, Config $config, int $confirmed): array|SendStart
    {
        $json = trim((string) $item->getValue(Schema::CAMPAIGN_FILTER));
        if ($json === '') {
            // the default filter only previews a draft that never saved one
            return SendStart::refused(SendStart::NO_FILTER);
        }
        try {
            $filter = RecipientFilter::fromJson($json, $setup);
        } catch (\InvalidArgumentException $e) {
            return SendStart::refused(SendStart::BAD_FILTER, $e->getMessage());
        }
        $recipients = $this->resolver()->resolve($setup, $filter, $config);
        $sendable   = count(array_filter($recipients, static fn(Recipient $r): bool => $r->sendable()));

        return match (true) {
            $sendable !== $confirmed          => SendStart::refused(SendStart::COUNT_CHANGED),
            $sendable > $setup->maxRecipients => SendStart::refused(SendStart::TOO_MANY),
            $sendable === 0                   => SendStart::refused(SendStart::NOTHING),
            default                           => $recipients,
        };
    }

    /**
     * The letter as a data message carries it, frozen with the recipients. Its size is known once it is
     * read - at most one message, as Letter::of() stops there -, so memory is checked before the
     * snapshot, which takes the rest.
     * @param list<Recipient> $recipients
     */
    private function snapshot(Setup $setup, ItemContent $item, array $recipients, string $campaign): Snapshot|SendStart
    {
        try {
            $uploads = $this->uploads($setup->campaignSlice);
        } catch (\InvalidArgumentException $e) {
            return SendStart::refused(SendStart::CONTENT, $e->getMessage());
        }
        try {
            $letter = Letter::of($item, $setup->letterhead, $uploads);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // what to fix in the campaign: it reads the uploads, never the database
            return SendStart::refused(SendStart::CONTENT, $e->getMessage());
        }
        // as SendRun: free pages PHP keeps are no need
        gc_mem_caches();
        $need  = memory_get_usage(true) + self::MEMORY_SLACK + self::MEMORY_PER_BYTE * array_sum(array_map(static fn(File $f): int => $f->size(), $letter->files));
        $limit = $this->memoryLimit();
        // raised for the rest of the request, which only shows the page then
        if (($limit >= 0) && ($limit < $need) && is_null($this->raiseMemoryLimit($need))) {
            return SendStart::refused(SendStart::MEMORY, 'mb:' . (int) ceil($need / (1024 * 1024)));
        }
        try {
            return Snapshot::of($letter, $recipients, $campaign);
        } catch (\InvalidArgumentException $e) {
            return SendStart::refused(SendStart::CONTENT, $e->getMessage());
        }
    }

    /**
     * The snapshot, the run and the campaign queued in one transaction - all or nothing, the run looked
     * for once more inside it
     * @param array<string, string> $props the run's own properties so far
     */
    private function start(Setup $setup, string $campaign, string $uid, Snapshot $snapshot, int $sendable, array $props): SendStart
    {
        try {
            // the campaign item is written with the run, so its tables too
            BatchRun::requireTransactional(['item', 'content']);
        } catch (BatchFailed $e) {
            return self::refusedBy($e);
        }
        $runClass = $this->runClass();
        $items    = $this->items($setup, $campaign);
        $now      = $runClass::clock();
        $name     = AA::$perm->getUserName($uid);
        try {
            $runId = DB_AA::transaction(function () use ($runClass, $items, $campaign, $uid, $name, $snapshot, $sendable, $props, $now): string {
                // without one the snapshot would stay when the run cannot start
                if (!DB_AA::inTransaction()) {
                    throw new BatchFailed(BatchRun::NO_TRANSACTION);
                }
                if ($runClass::existsFor($campaign)) {
                    throw new BatchFailed(SendStart::ALREADY);
                }
                $run    = $runClass::begin($campaign, $uid, $snapshot, $props);
                $queued = $items->campaign(Schema::CAMPAIGN_STATE_QUEUED, [
                    Schema::CAMPAIGN_ENVIRONMENT  => $props['env'],
                    Schema::CAMPAIGN_SENDER_BOX   => $props['sender_box'],
                    Schema::CAMPAIGN_RECIPIENTS   => $sendable,
                    Schema::CAMPAIGN_SENT_BY      => $uid,
                    Schema::CAMPAIGN_SENT_BY_NAME => $name,
                    Schema::CAMPAIGN_STARTED_AT   => $now,
                ]);
                return $queued ? (string) $run->getId() : throw new BatchFailed(SendStart::UNKNOWN);
            });
        } catch (\Throwable $e) {
            // the item cache keeps the campaign as the write rolled back stored it
            AA_Items::singleton()->invalidate();
            AA::Contentcache()->clear_soft();
            if ($e instanceof BatchFailed) {
                return self::refusedBy($e);
            }
            throw $e;
        }
        $this->log($campaign, $uid, $runId);

        return SendStart::queued($runId);
    }

    /** the code of $e; its detail the place (the table of NO_TRANSACTION) or the detail (the status ISDS refused with) */
    private static function refusedBy(BatchFailed $e): SendStart
    {
        return SendStart::refused($e->reason, $e->where ?: $e->detail);
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<SendRun> */
    protected function runClass(): string
    {
        return SendRun::class;
    }

    protected function conf(): DatovkaConf
    {
        return DatovkaConf::load();
    }

    protected function rules(): SliceRules
    {
        return new SliceRules();
    }

    protected function resolver(): Recipients
    {
        return new Recipients();
    }

    protected function client(Account $account): Client
    {
        return new Client($account);
    }

    /** the campaign item as stored now, null = none */
    protected function campaign(string $id): ?ItemContent
    {
        $content = AA_Items::getItem(new zids($id, 'l'), null, true)?->getItemContent();
        return ($content instanceof ItemContent) ? $content : null;
    }

    /** @throws \InvalidArgumentException the uploads of the slice are not set right */
    protected function uploads(string $slice): UploadDir
    {
        return UploadDir::forSlice(AA_Slice::getOwnModule($slice) ?? throw new \InvalidArgumentException("There is no slice $slice"));
    }

    protected function items(Setup $setup, string $campaign): SendItems
    {
        return new SendItems($setup->campaignSlice, $setup->messageSlice, $campaign);
    }

    /** @return bool got it - unlock() then; false also when the database gives no lock at all */
    protected function lock(string $campaign): bool
    {
        return DB_AA::lock(SendRun::lockName($campaign));
    }

    protected function unlock(string $campaign): void
    {
        DB_AA::unlock(SendRun::lockName($campaign));
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

    protected function log(string $campaign, string $uid, string $runId): void
    {
        AA_Log::write(self::LOG_EVENT, "queued:$campaign", [$uid, $runId]);
    }
}
