<?php

declare(strict_types=1);

namespace AA\Isds;

use AA;
use AA\IO\DB\DB_AA;
use AA\Isds\Registry\Box;
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
 * A test message (test()) goes from a draft at once, in the request, to a test box of the page: one
 * message, its message item, no run, the campaign untouched.
 */
class SendQueue
{
    /** log.type of a sending queued, with the user and the run */
    public const LOG_EVENT = 'ISDS_SEND';
    /** log.type of a test message: who sent it and its files, which its message item cannot hold */
    public const TEST_LOG_EVENT = 'ISDS_TEST';

    /** before the subject of a test message: an authority files it among its incoming letters; '' = none */
    public const TEST_SUBJECT = 'ZKOUŠKA: ';

    /**
     * Memory a snapshot takes beyond the letter read - its base64 rows and a row escaped for its INSERT:
     * over a file of 1, 5 and 20 MB, Letter::of() took 1.0 times the files at its peak and with
     * Snapshot::of() 2.1, 2.7 and 2.8 times (memory_get_usage(true)).
     */
    private const MEMORY_PER_BYTE = 2;
    private const MEMORY_SLACK    = 16 * 1024 * 1024;

    /** seconds a letter may take to be made from now: PdfBody stopped the slowest one measured at 5.1 s */
    private const LETTER_SECONDS = 60;

    /** a test message still sending after its request could have ended died on its way - it may have gone */
    private const TEST_DEAD_AFTER = Client::CREATE_TIMEOUT + 60;

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
            if (!$this->lock(SendRun::lockName($campaign))) {
                return SendStart::refused(SendStart::BUSY);
            }
            try {
                return $this->freeze($setup, $campaign, $uid, $confirmed, $config, $props);
            } finally {
                $this->unlock(SendRun::lockName($campaign));
            }
        });
    }

    /**
     * One test message of the draft, now, to a test box of the page - the letter as stored, with
     * TEST_SUBJECT before its subject. Each letter goes to a box once: one that went or may have gone
     * there is not sent again until the draft changes (its checksum, sum()). In production a message
     * that would be paid, or of which ISDS does not say, is refused. Only the test action of
     * {datovkabulk} calls it, after Access, the form token and the check of a foreign origin.
     * CONTENT also when the subject with TEST_SUBJECT is longer than ISDS takes.
     * @param string $uid as queue()
     * @param string $box the box the user chose; '' = the only test box of the page
     * @throws \Throwable as queue(): a failure no code tells. One after the message item is written
     *                    leaves it queued, sending, or uncertain when CreateMessage threw it - the next
     *                    test settles a sending one as uncertain
     */
    public function test(Setup $setup, string $campaign, string $uid, string $box): TestResult
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('A test message is sent outside any transaction');
        }
        $campaign = ModuleId::normalize($campaign);
        if (is_null($campaign)) {
            return TestResult::refused(TestResult::UNKNOWN);
        }
        if (!Access::userMay($uid, $setup->campaignSlice)) {
            return TestResult::refused(TestResult::FORBIDDEN);
        }
        if ($setup->testBoxes === []) {
            return TestResult::refused(TestResult::NO_TEST_BOX);
        }
        $box = self::testBox($setup, $box);
        if (is_null($box)) {
            return TestResult::refused(TestResult::NOT_TEST_BOX);
        }
        // known of a hosted filing service only; ISDS refuses the others
        if ($box === $setup->senderBox) {
            return TestResult::refused(TestResult::OWN_BOX);
        }
        $ready = $this->ready($setup, false);
        if ($ready instanceof SendStart) {
            return TestResult::refused($ready->code, $ready->detail);
        }
        [$binding, $account] = $ready;
        // the page's list again, as the account has it: production never trusts the template alone
        if (($binding->environment === Account::PRODUCTION) && !in_array($box, $binding->testBoxes, true)) {
            return TestResult::refused(TestResult::NOT_TEST_BOX);
        }
        $end = SendPace::maintenanceEnd($this->runClass()::clock());
        if (!is_null($end)) {
            return TestResult::refused(TestResult::MAINTENANCE, (string) $end);
        }
        return AA_Credentials::singleton()->withSlicesOpen([$setup->campaignSlice, $setup->messageSlice], function () use ($setup, $campaign, $box, $binding, $account): TestResult {
            $item = $this->draft($setup, $campaign);
            if ($item instanceof SendStart) {
                return TestResult::refused($item->code);
            }
            $letter = $this->letter($setup, $item);
            if ($letter instanceof SendStart) {
                return TestResult::refused($letter->code, $letter->detail);
            }
            try {
                $message = new Message($box, self::TEST_SUBJECT . $letter->annotation, $letter->files, '', $letter->toHands);
            } catch (\InvalidArgumentException $e) {
                // the subject with the prefix may be too long
                return TestResult::refused(SendStart::CONTENT, $e->getMessage());
            }
            $memory = $this->memoryFor($message->size(), SendRun::MEMORY_PER_BYTE);
            if ($memory !== '') {
                return TestResult::refused(SendStart::MEMORY, $memory);
            }
            // one test of the pair at a time: none sees another one half written
            $lock = 'isds-test:' . $setup->messageSlice;
            if (!$this->lock($lock)) {
                return TestResult::refused(TestResult::BUSY);
            }
            try {
                return $this->sendTest($setup, $campaign, (string) $item->getValue('short_id........'), $message, $letter->source, $binding, $account);
            } finally {
                $this->unlock($lock);
            }
        });
    }

    /**
     * Under the lock: the earlier tests, the price, the message item, the message and what came of it
     * @param string $mainSource Letter::$source of the main file
     */
    private function sendTest(Setup $setup, string $campaign, string $campShort, Message $message, string $mainSource, Binding $binding, Account $account): TestResult
    {
        $runClass = $this->runClass();
        $items    = $this->items($setup, $campaign);
        $sum      = self::sum($message, $mainSource);
        $box      = $message->recipient;
        foreach ($items->tests() as $test) {
            if ($test['state'] === Schema::MESSAGE_STATE_SENDING) {
                // the request of a test that died: no lock held, so its process is gone
                if ($runClass::clock() - $test['sending'] <= self::TEST_DEAD_AFTER) {
                    return TestResult::refused(TestResult::BUSY);
                }
                $items->mark($test['id'], Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, $runClass::clock());
                $test['state'] = Schema::MESSAGE_STATE_UNCERTAIN;
            }
            // only failed and queued sent nothing for sure; a state or a checksum edited by hand may be this letter
            if (($test['box'] === $box) && !in_array($test['state'], [Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_QUEUED], true) && in_array($test['sum'], [$sum, ''], true)) {
                return TestResult::refused(TestResult::SAME_LETTER, msg: $test['id'], ref: $test['ref'], dmId: $test['dmId'], at: $test['sending']);
            }
        }
        // the price asked and the message sent, each to its timeout
        $this->keepRunning(Client::TIMEOUT + Client::CREATE_TIMEOUT + 60);
        $client     = $this->client($account);
        $production = ($binding->environment === Account::PRODUCTION);
        try {
            $paid = $client->wouldBePaid($box);
        } catch (AuthException|ConfigException $e) {
            // the login cannot work: CreateMessage would fail alike, nothing is sent, nothing paid
            return TestResult::of(Outcome::of($e), '', $box, '', null);
        } catch (\Exception $e) {
            // a box ISDS does not know too: whatever ISDS did not answer is no "free"
            if ($production) {
                return TestResult::refused(TestResult::PAID_UNKNOWN, isdsCode: ($e instanceof IsdsException) ? $e->isdsCode() : '');
            }
            $paid = null;
        }
        if ($production && $paid) {
            return TestResult::refused(TestResult::PAID);
        }
        $msg = new_id();
        $items->create(['msg' => $msg, 'src' => '', 'box' => $box, 'name' => $box, 'reason' => ''], $binding->environment, $setup->senderBox, $runClass::clock(), test: true);
        $short = $items->shortId($msg);
        if ($short === '') {
            throw new \RuntimeException("The test message item $msg is not to be read");
        }
        $ref     = "$campShort-T$short";
        $message = new Message($box, $message->annotation, $message->files, $ref, $message->toHands);
        $this->testLog($campaign, $msg, $sum, self::testLogRows($message->files, $mainSource));
        $items->markTestSending($msg, $runClass::clock(), $ref, $sum, count($message->files));
        try {
            $result = $client->createMessage($message);
        } catch (\Throwable $e) {
            $result = $e;
        }
        $outcome = Outcome::of($result);
        if ($outcome->state === Schema::MESSAGE_STATE_QUEUED) {
            // nothing left, and a test is never tried again by itself
            $items->mark($msg, Schema::MESSAGE_STATE_FAILED, Schema::EVENT_FAILED, $runClass::clock(), code: $outcome->code);
        } else {
            $items->markOutcome($msg, $outcome, $runClass::clock());
        }
        // a failure AA does not know: the item shows it uncertain, the caller learns of it as of any other
        if (($outcome->then === Outcome::RETHROW) && ($result instanceof \Throwable)) {
            throw $result;
        }
        return TestResult::of($outcome, $msg, $box, $ref, $paid);
    }

    /** the box asked for among the test boxes of the page, as ISDS knows it; null = none of them */
    private static function testBox(Setup $setup, string $box): ?string
    {
        if (trim($box) === '') {
            return (count($setup->testBoxes) === 1) ? $setup->testBoxes[0] : null;
        }
        try {
            $box = Box::normalizeId($box);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return in_array($box, $setup->testBoxes, true) ? $box : null;
    }

    /**
     * The checksum of what the recipient sees: the subject, "to hands" and each file - its name, type
     * and content - in the order of the message. The main file counts by what it is made of, as the
     * same draft need not give the same bytes; the letterhead is in it. Whatever changes the checksum
     * of the same letter - another way of counting it, another dompdf or look of the PDF (PdfBody::source()),
     * an update of libgd that draws the images of the letter - lets it be tested once more.
     * @param string $mainSource Letter::$source of the main file
     * @return string 32 hexadecimal digits
     */
    private static function sum(Message $message, string $mainSource): string
    {
        $files = array_map(static fn(File $f): array => ($f->metaType === File::MAIN) ? [$f->name, $f->mimeType, $mainSource] : [$f->name, $f->mimeType, $f->size(), hash('xxh128', $f->content)], $message->files);
        return hash('xxh128', json_encode([$message->annotation, $message->toHands, $files], JSON_THROW_ON_ERROR));
    }

    /**
     * The account bound to the site and the two slices, and the rules of the slices and the source -
     * as the page checked them, once more: nothing else stands between a request and a run
     * @param bool $source the source slice too; a test message has no recipients
     * @return ($source is true ? array{Binding, Account, Config}|SendStart : array{Binding, Account, null}|SendStart)
     */
    private function ready(Setup $setup, bool $source = true): array|SendStart
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
            ...($source ? $rules->sourceProblems($setup->site, $setup->sourceSlice, array_map('strval', array_keys($setup->types)), $setup->memberField) : []),
        ];
        // Recipients::resolve() refuses settings without a type field; sourceProblems() read its own copy
        $config = $source ? $rules->registryConfig($setup->sourceSlice) : null;
        if ($problems || ($source && (is_null($config) || ($config->typeField() === '')))) {
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
        $letter = $this->letter($setup, $item);
        if ($letter instanceof SendStart) {
            return $letter;
        }
        $memory = $this->memoryFor(array_sum(array_map(static fn(File $f): int => $f->size(), $letter->files)), self::MEMORY_PER_BYTE);
        if ($memory !== '') {
            return SendStart::refused(SendStart::MEMORY, $memory);
        }
        try {
            return Snapshot::of($letter, $recipients, $campaign);
        } catch (\InvalidArgumentException $e) {
            return SendStart::refused(SendStart::CONTENT, $e->getMessage());
        }
    }

    /** the letter of the campaign item; refused CONTENT with what to fix, or MEMORY for its PDF */
    protected function letter(Setup $setup, ItemContent $item): Letter|SendStart
    {
        $this->timeForLetter();
        try {
            return Letter::of($item, $setup->letterhead, $this->uploads($setup->campaignSlice), fn(int $bytes): string => $this->memoryFor($bytes, 1));
        } catch (\OverflowException $e) {
            return SendStart::refused(SendStart::MEMORY, $e->getMessage());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // what to fix in the campaign: it reads the uploads, never the database
            return SendStart::refused(SendStart::CONTENT, $e->getMessage());
        }
    }

    /** dompdf takes up to PdfBody::MAX_SECONDS and a moment more: the time of the request is raised, never cut */
    private function timeForLetter(): void
    {
        $limit = $this->timeLimit();
        if ($limit > 0) {
            $this->setTimeLimit(max(self::LETTER_SECONDS, $limit));
        }
    }

    /**
     * memory_limit raised for the rest of the request - which only shows the page then - when it is
     * lower than what $bytes of files take
     * @return string '' = enough; else the MB needed, the detail of MEMORY
     */
    private function memoryFor(int $bytes, int $perByte): string
    {
        // as SendRun: free pages PHP keeps are no need
        gc_mem_caches();
        $need  = memory_get_usage(true) + self::MEMORY_SLACK + $perByte * $bytes;
        $limit = $this->memoryLimit();
        if (($limit >= 0) && ($limit < $need) && is_null($this->raiseMemoryLimit($need))) {
            return 'mb:' . (int) ceil($need / (1024 * 1024));
        }
        return '';
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
    protected function lock(string $name): bool
    {
        return DB_AA::lock($name);
    }

    protected function unlock(string $name): void
    {
        DB_AA::unlock($name);
    }

    /** the request goes on for $seconds more, also when the browser leaves: a message sent is marked */
    protected function keepRunning(int $seconds): void
    {
        ignore_user_abort(true);
        set_time_limit($seconds);
    }

    /** max_execution_time, 0 = none */
    protected function timeLimit(): int
    {
        return (int) ini_get('max_execution_time');
    }

    protected function setTimeLimit(int $seconds): void
    {
        set_time_limit($seconds);
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

    /**
     * What a test message sends: a row of it - log.user tells who -, then a row of each file
     * (testLogRows()). log.params holds 128 bytes.
     * @param list<string> $rows
     */
    protected function testLog(string $campaign, string $msg, string $sum, array $rows): void
    {
        AA_Log::write(self::TEST_LOG_EVENT, "test:$campaign", [$msg, $sum]);
        AA_Log::write(self::TEST_LOG_EVENT, $rows, "file:$msg");
    }

    /**
     * A row of each file: its number, size and checksum, then its name - the main file also with what it
     * is made of, the part of sum() the bytes do not show. log.selector holds 255 bytes: a long name is cut
     * @param list<File> $files
     * @param string     $mainSource Letter::$source of the main file
     * @return list<string>
     */
    private static function testLogRows(array $files, string $mainSource): array
    {
        $rows = [];
        foreach ($files as $i => $f) {
            $source = ($f->metaType === File::MAIN) ? [$mainSource] : [];
            $rows[] = mb_strcut(ParamImplode([$i + 1, $f->size(), hash('xxh128', $f->content), ...$source, $f->name]), 0, 255, 'UTF-8');
        }
        return $rows;
    }
}
