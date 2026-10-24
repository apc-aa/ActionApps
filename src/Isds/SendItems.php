<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Later\BatchFailed;
use AA_Set;
use AA_Value;
use ItemContent;
use zids;

/**
 * The items a mass sending writes: one in the message slice per frozen recipient, and the fields of
 * the campaign the sending owns. They are for people to see - what goes out and to whom comes from
 * the run's record, so an item edited by hand sends nothing. Message items are written without AA
 * events and feeding: an ITEM_NEW mail per recipient, a copy in another slice. Nor do they touch the
 * page cache - the caller invalidates the message slice once a step.
 */
class SendItems
{
    /** the campaign fields the sending writes; the rest is the letter, which only a draft changes */
    public const CAMPAIGN_OWN = [
        Schema::CAMPAIGN_ERROR_TEXT,
        Schema::CAMPAIGN_ENVIRONMENT,
        Schema::CAMPAIGN_SENDER_BOX,
        Schema::CAMPAIGN_RECIPIENTS,
        Schema::CAMPAIGN_SENT_BY,
        Schema::CAMPAIGN_SENT_BY_NAME,
        Schema::CAMPAIGN_STARTED_AT,
        Schema::CAMPAIGN_FINISHED_AT,
    ];

    /** storeItem() flags: invalidate the campaign's pages, no feeding, no events */
    private const CAMPAIGN_FLAGS = [true, false, false, false];

    /** entries a timeline keeps besides its first one */
    private const EVENTS_KEPT = 100;

    /** bytes of a text in a timeline entry: Soap::quote() cuts there and adds '…' (3 bytes) */
    private const LONGEST_VALUE = Soap::QUOTE + 3;

    /** EVENTS_KEPT + 1 entries with a code and a message of LONGEST_VALUE, escaped to at most three
     *  times their bytes (a 4-byte letter is two \u escapes), stay under 200 KB; a longer text is no
     *  timeline written here, and decoding it could take more memory than a step has */
    private const LONGEST_TIMELINE = 256 * 1024;

    /** the keys of a timeline entry; sum and files only in the sending of a test message */
    private const ENTRY_KEYS = ['at', 'kind', 'dmID', 'code', 'message', 'sum', 'files'];

    /** the checksum of the letter of a test message (SendQueue) */
    private const SUM = '/^[0-9a-f]{32}\z/';

    /** a word of Schema::BOX_PROBLEMS, a run's code or dmMessageStatus digits: no room for a text or a path */
    private const CODE = '/^[0-9A-Za-z._-]{0,64}\z/';

    /** the states that say what went wrong; any other clears the error of the message */
    private const PROBLEMS = [Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_SKIPPED];

    /** dmMessageStatus => the kind of its entry in the timeline; 7, 9 and 10 change MESSAGE_DM_STATUS only */
    private const STATUS_EVENTS = [
        1 => Schema::EVENT_SUBMITTED,
        2 => Schema::EVENT_STAMPED,
        3 => Schema::EVENT_AV,
        4 => Schema::EVENT_DELIVERED,
        5 => Schema::EVENT_FICTION,
        6 => Schema::EVENT_LOGIN,
        8 => Schema::EVENT_UNDELIVERABLE,
    ];

    /**
     * @param string $campaignSlice unpacked id
     * @param string $messageSlice  unpacked id
     * @param string $campaign      long id of the campaign item
     */
    public function __construct(
        private readonly string $campaignSlice,
        private readonly string $messageSlice,
        private readonly string $campaign,
    ) {}

    /**
     * The message item of a frozen recipient, under the id frozen with it: a step that died after
     * it is repeated harmlessly
     * @param array<string, string> $row         Snapshot::recipients() row
     * @param string                $environment Account::TEST or Account::PRODUCTION
     * @param bool                  $stopped     the sending stopped before the recipient was tried; one
     *                                           skipped by its reason stays skipped
     * @param bool                  $test        a test message of the campaign, of no recipient frozen
     * @return bool created now; false = it is there already
     * @throws \RuntimeException not stored
     */
    public function create(array $row, string $environment, string $senderBox, int $at, bool $stopped = false, bool $test = false): bool
    {
        $reason = $row['reason'];
        if (($reason !== '') && !in_array($reason, Schema::BOX_PROBLEMS, true)) {
            throw new \InvalidArgumentException('A recipient is skipped for one of Schema::BOX_PROBLEMS');
        }
        if ($test && (($reason !== '') || $stopped)) {
            throw new \InvalidArgumentException('A test message is sent, never skipped or stopped');
        }
        if (!in_array($environment, [Account::TEST, Account::PRODUCTION], true)) {
            throw new \InvalidArgumentException('An ISDS environment is Account::TEST or Account::PRODUCTION');
        }
        $skipped = ($reason !== '');
        [$state, $kind] = match (true) {
            $skipped => [Schema::MESSAGE_STATE_SKIPPED, Schema::EVENT_SKIPPED],
            $stopped => [Schema::MESSAGE_STATE_STOPPED, Schema::EVENT_STOPPED],
            default  => [Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED],
        };
        $event   = ['at' => $at, 'kind' => $kind] + ($skipped ? ['code' => $reason] : []);
        $fields  = [
            'status_code.....'          => \SC_ACTIVE,
            Schema::MESSAGE_NAME        => $row['name'],
            Schema::MESSAGE_BOX         => $row['box'],
            Schema::MESSAGE_ENVIRONMENT => $environment,
            Schema::MESSAGE_SENDER_BOX  => $senderBox,
            Schema::MESSAGE_CAMPAIGN    => $this->campaign,
            Schema::MESSAGE_RECIPIENT   => $row['src'],
            // a view leaving test messages out (test = 0) would miss a message without the number
            Schema::MESSAGE_TEST        => $test ? 1 : 0,
            Schema::MESSAGE_STATE       => $state,
            Schema::MESSAGE_EVENTS      => self::encoded([$event]),
        ] + ($skipped ? [Schema::MESSAGE_ERROR_CODE => $reason] : []);
        return $this->whileOpen($this->messageSlice, function () use ($row, $fields): bool {
            if ($this->save($this->messageSlice, $row['msg'], $fields, 'insert_if_new', ItemContent::FLAG_JUST_STORE)) {
                return true;
            }
            if (ItemContent::lastErr() === \ITEMCONTENT_ERROR_DUPLICATE) {
                return false;
            }
            throw new \RuntimeException('Message item ' . $row['msg'] . ' not stored, error ' . ItemContent::lastErr());
        });
    }

    /**
     * The message item moves to $state and $event joins its timeline (MESSAGE_EVENTS). An error text
     * comes only through markOutcome(): an exception's message may name the file of a key.
     * @param string $dmId      digits, as ISDS numbers messages
     * @param string $code      CODE; one of Schema::BOX_PROBLEMS for a skipped message. ISDS's own codes go only through markOutcome()
     * @param string $senderRef CODE letters, at most Message::MAX_REF
     * @param bool   $once      nothing written when the item shows $state already: a stop or a lookup
     *                          repeated after its step died adds no second entry
     * @return bool false = no such message item of this campaign, deleted by hand - nothing written
     * @throws \RuntimeException not stored
     */
    public function mark(string $msg, string $state, string $event, int $at, string $dmId = '', string $code = '', string $senderRef = '', bool $once = false): bool
    {
        self::checkWords($dmId, $code, $senderRef);
        return $this->write($msg, $state, $event, $at, $dmId, $code, '', $senderRef, once: $once);
    }

    /**
     * A test message on its way: its entry of sending keeps the checksum of the letter, by which the
     * same letter is not tested on the same box twice, and the count of its files - a list of them
     * would not fit an entry (LONGEST_VALUE)
     * @param string $sum SendQueue's checksum, 32 hexadecimal digits
     * @return bool as mark()
     * @throws \RuntimeException not stored
     */
    public function markTestSending(string $msg, int $at, string $senderRef, string $sum, int $files): bool
    {
        self::checkWords('', '', $senderRef);
        if ((preg_match(self::SUM, $sum) !== 1) || ($files < 1)) {
            throw new \InvalidArgumentException('A test message has a checksum of 32 hexadecimal digits and a file at least');
        }
        return $this->write($msg, Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, $at, '', '', '', $senderRef, more: ['sum' => $sum, 'files' => $files]);
    }

    /**
     * The test messages of the campaign, in every bin: one moved to the trash or edited to wait does
     * not take back a letter that went
     * @return list<array{id: string, box: string, state: string, ref: string, dmId: string, sending: int, sum: string}>
     *         sending - when it was sent, 0 = not known; sum - the checksum of its letter, '' = not known
     */
    public function tests(): array
    {
        $fields = [Schema::MESSAGE_CAMPAIGN, Schema::MESSAGE_TEST, Schema::MESSAGE_BOX, Schema::MESSAGE_STATE, Schema::MESSAGE_SENDER_REF, Schema::MESSAGE_DM_ID, Schema::MESSAGE_EVENTS];
        return $this->whileOpen($this->messageSlice, function () use ($fields): array {
            $tests = [];
            foreach ($this->testIds() as $id) {
                $shown = $this->read($this->messageSlice, $id, $fields);
                if (is_null($shown) || (strtolower($shown[Schema::MESSAGE_CAMPAIGN]) !== strtolower($this->campaign)) || ($shown[Schema::MESSAGE_TEST] !== '1')) {
                    continue;
                }
                $sending = [];
                foreach (self::timeline($shown[Schema::MESSAGE_EVENTS]) as $entry) {
                    if (($entry['kind'] ?? null) === Schema::EVENT_SENDING) {
                        $sending = $entry;
                    }
                }
                $sum     = (string) ($sending['sum'] ?? '');
                $tests[] = [
                    'id'      => $id,
                    'box'     => strtolower(trim($shown[Schema::MESSAGE_BOX])),
                    'state'   => $shown[Schema::MESSAGE_STATE],
                    // what a page shows of it: an item edited by hand may hold anything
                    'ref'     => self::isRef($shown[Schema::MESSAGE_SENDER_REF]) ? $shown[Schema::MESSAGE_SENDER_REF] : '',
                    'dmId'    => (preg_match('/^\d{0,20}\z/', $shown[Schema::MESSAGE_DM_ID]) === 1) ? $shown[Schema::MESSAGE_DM_ID] : '',
                    'sending' => is_int($sending['at'] ?? null) ? $sending['at'] : 0,
                    'sum'     => (preg_match(self::SUM, $sum) === 1) ? $sum : '',
                ];
            }
            return $tests;
        });
    }

    /** the short id of the message item $msg of this campaign, '' = none */
    public function shortId(string $msg): string
    {
        return $this->whileOpen($this->messageSlice, function () use ($msg): string {
            $shown = $this->read($this->messageSlice, $msg, ['short_id........', Schema::MESSAGE_CAMPAIGN]);
            return (strtolower($shown[Schema::MESSAGE_CAMPAIGN] ?? '') === strtolower($this->campaign)) ? $shown['short_id........'] : '';
        });
    }

    /** mark() with what Outcome::of() made of an attempt, its text too */
    public function markOutcome(string $msg, Outcome $outcome, int $at): bool
    {
        return $this->write($msg, $outcome->state, $outcome->event, $at, $outcome->dmId, $outcome->isdsCode, $outcome->text, '');
    }

    /**
     * A message of unknown fate the list of sent ones holds: sent when it was tried - ISDS took it
     * within that request -, delivered as the list says; failed for good, its status the code, when
     * the antivirus check refused it. ISDS tells no time of the refusal, so its entry is dated 0 (not
     * known); the time AA found the message is that of lookup-found. Nothing is written when the item
     * shows the state, the message id and the time it was sent already: a lookup repeated after its
     * step died adds no second entry, and one after markDelivery() still completes the item.
     * @param int $triedAt the time of the attempt the record keeps
     * @return bool false = no such message item of this campaign - nothing written
     * @throws \RuntimeException not stored
     */
    public function markFound(string $msg, SentMessage $sent, int $triedAt, int $at): bool
    {
        $refused  = ($sent->status === SentMessage::AV_REFUSED);
        $code     = $refused ? (string) $sent->status : '';
        $delivery = [
            Schema::MESSAGE_DM_STATUS    => $sent->status,
            Schema::MESSAGE_SENT_AT      => $triedAt,
            Schema::MESSAGE_DELIVERED_AT => $sent->deliveredAt,
            Schema::MESSAGE_ACCEPTED_AT  => $sent->acceptedAt,
        ];
        $fields = [
            Schema::MESSAGE_STATE      => $refused ? Schema::MESSAGE_STATE_FAILED : Schema::MESSAGE_STATE_SENT,
            Schema::MESSAGE_ERROR_CODE => $code,
            Schema::MESSAGE_ERROR_TEXT => '',
        ] + self::given([Schema::MESSAGE_DM_ID => $sent->dmId]) + array_filter($delivery, is_int(...));
        $found = ['at' => $at, 'kind' => Schema::EVENT_LOOKUP_FOUND] + self::given(['dmID' => $sent->dmId]);
        $av    = $refused ? [['at' => 0, 'kind' => Schema::EVENT_AV, 'code' => $code]] : [];
        return $this->update($msg, $fields, $found, $av, [Schema::MESSAGE_STATE, Schema::MESSAGE_DM_ID, Schema::MESSAGE_SENT_AT]);
    }

    /**
     * The message item shows its delivery as the record keeps it: sent under $dmId, the status, failed
     * with the status as its code for StatusSync::FAILED. The timeline gets an entry of the status, of
     * the delivery and of an acceptance after 10 days, each once by its kind.
     * - The times of delivery and acceptance are the record's, in the fields and in the timeline alike:
     *   the record keeps the earliest ISDS told, so an earlier one moves both. An entry of a status is
     *   dated by the status - login too: an acceptance by a login shows in ACCEPTED_AT.
     * - A time ISDS did not tell is 0, not the time of this write, which would pass for one of ISDS with
     *   legal weight; 0 takes nothing away, so a time markFound() wrote from the list stays. An acceptance
     *   after 10 days at 0 stays at 0 even when ACCEPTED_AT gets a time: that may be the login's.
     * Nothing is written when the item shows it all already, and no text of ISDS ever.
     * @param array{s: int, t: int, d: int, a: int} $entry StatusSync::entry()
     * @param string                                $dmId  of the message in the record (dm_ids)
     * @return ?array{s: int, t: int, d: int, a: int} $entry as written - the step compares it with the
     *                                                  record; null = no such message item of this
     *                                                  campaign, nothing written
     * @throws \RuntimeException not stored
     */
    public function markDelivery(string $msg, array $entry, string $dmId): ?array
    {
        if ((array_filter($entry, is_int(...)) !== $entry) || (StatusSync::entry(implode(':', $entry)) !== $entry)) {
            throw new \InvalidArgumentException('A delivery is an entry as StatusSync::entry() reads it');
        }
        if (preg_match('/^\d{1,20}\z/', $dmId) !== 1) {
            throw new \InvalidArgumentException('A message id is digits, as ISDS numbers messages');
        }
        ['s' => $s, 't' => $t, 'd' => $d, 'a' => $a] = $entry;
        $failed = in_array($s, StatusSync::FAILED, true);
        $own    = self::STATUS_EVENTS[$s] ?? null;
        // when ISDS took it is SENT_AT, the time AA sent it; the record has no time of 1
        $times = is_null($own) ? [] : [$own => ($s === 1) ? 0 : $t];
        if ($d > 0) {
            // status 4 is dated by its delivery
            $times[Schema::EVENT_DELIVERED] = $d;
        }
        // a login makes 6, which 8 excludes: the acceptance of 4, 5 or 8 is the one after 10 days; that of
        // 6 too when it came well before the login - seconds apart, two sources tell the login itself, and
        // the list tells no time of the login (t = 0), so its acceptance may be the login's
        if (($a > 0) && (in_array($s, [4, 5, 8], true) || (($s === 6) && ($a < $t - Lookup::SKEW)))) {
            $times[Schema::EVENT_FICTION] = $a;
        }
        $perKind = [];
        // in the order a message goes through them
        foreach (Schema::EVENT_KINDS as $kind) {
            if (isset($times[$kind])) {
                $code      = ($failed && ($kind === $own)) ? ['code' => (string) $s] : [];
                $perKind[] = ['at' => $times[$kind], 'kind' => $kind] + $code;
            }
        }
        $fields = [
            Schema::MESSAGE_STATE      => $failed ? Schema::MESSAGE_STATE_FAILED : Schema::MESSAGE_STATE_SENT,
            Schema::MESSAGE_ERROR_CODE => $failed ? (string) $s : '',
            Schema::MESSAGE_ERROR_TEXT => '',
            Schema::MESSAGE_DM_ID      => $dmId,
            Schema::MESSAGE_DM_STATUS  => $s,
        ] + array_filter([Schema::MESSAGE_DELIVERED_AT => $d, Schema::MESSAGE_ACCEPTED_AT => $a]);
        // whatever the status, an acceptance after 10 days the timeline has is the one ACCEPTED_AT shows
        $redate = ($a > 0) ? [['at' => $a, 'kind' => Schema::EVENT_FICTION]] : [];
        return $this->update($msg, $fields, entry: null, perKind: $perKind, once: [], redate: $redate) ? $entry : null;
    }

    /**
     * The state of the campaign, and fields of CAMPAIGN_OWN. An error goes only as the run's code,
     * translated when shown: an exception's text may carry the path of a key. Any other state clears it.
     * @param array<string, string|int> $fields CAMPAIGN_OWN field => value; CAMPAIGN_ERROR_TEXT with the
     *                                          state error, and only with it
     * @param bool                      $once   nothing written when the campaign shows $state already:
     *                                          an end repeated keeps the time it finished
     * @return bool false = the campaign item is gone - nothing written
     * @throws \RuntimeException not stored
     */
    public function campaign(string $state, array $fields = [], bool $once = false): bool
    {
        // a draft is what the sending started from; it never goes back to one
        if (($state === Schema::CAMPAIGN_STATE_DRAFT) || !in_array($state, Schema::CAMPAIGN_STATES, true)) {
            throw new \InvalidArgumentException('A sending moves a campaign to a state of Schema::CAMPAIGN_STATES after draft');
        }
        if (array_diff_key($fields, array_flip(self::CAMPAIGN_OWN)) !== []) {
            throw new \InvalidArgumentException('A sending writes only the campaign fields of SendItems::CAMPAIGN_OWN');
        }
        $error = (string) ($fields[Schema::CAMPAIGN_ERROR_TEXT] ?? '');
        if (($state === Schema::CAMPAIGN_STATE_ERROR) ? !BatchFailed::isCode($error) : ($error !== '')) {
            throw new \InvalidArgumentException('The error state takes a code of BatchFailed::CODE, and no other state takes one');
        }
        $fields = [Schema::CAMPAIGN_STATE => $state, Schema::CAMPAIGN_ERROR_TEXT => $error] + $fields;
        return $this->whileOpen($this->campaignSlice, function () use ($fields, $state, $once): bool {
            $shown = $this->read($this->campaignSlice, $this->campaign, [Schema::CAMPAIGN_STATE])[Schema::CAMPAIGN_STATE] ?? null;
            if (is_null($shown)) {
                return false;
            }
            if ($once && ($shown === $state)) {
                return true;
            }
            if (!$this->save($this->campaignSlice, $this->campaign, $fields, 'update', self::CAMPAIGN_FLAGS)) {
                throw new \RuntimeException("Campaign item $this->campaign not stored, error " . ItemContent::lastErr());
            }
            return true;
        });
    }

    /** @param list<bool> $flags see ItemContent::storeItem() */
    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        return $content->storeItem($mode, $flags) !== false;
    }

    /**
     * @param list<string> $fields
     * @return array<array-key, mixed> GetItemContent() of $fields of the item $id
     */
    protected function content(string $id, array $fields): array
    {
        return GetItemContent(new zids($id, 'l'), false, false, $fields);
    }

    /** @return list<string> long ids of the test messages of the campaign, in every bin */
    private function testIds(): array
    {
        $conds = [
            [Schema::MESSAGE_CAMPAIGN => 1, 'operator' => '==', 'value' => $this->campaign],
            [Schema::MESSAGE_TEST => 1, 'operator' => '==', 'value' => '1'],
        ];
        return $this->search(new AA_Set([$this->messageSlice], $conds, [['publish_date....' => 'a']], \AA_BIN_ALL));
    }

    /** @return list<string> long ids of the items of $set, AA's search */
    protected function search(AA_Set $set): array
    {
        return array_map('strval', $set->query()->longids());
    }

    /**
     * $fn with $slice open. storeItem() reads the item before an update with the reading password of
     * the request - masked, the history would list every field as changed - and keeps what it stored
     * in AA_Items. A slice the run opened already stays open after it.
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    protected function whileOpen(string $slice, \Closure $fn): mixed
    {
        return \AA_Credentials::singleton()->withSlicesOpen([$slice], $fn);
    }

    /**
     * The error fields hold the error of this state, cleared by one that is no problem; the timeline
     * keeps the earlier ones. $dmId and $senderRef left '' keep what the item has.
     * @param array<string, string|int> $more further keys of the entry (ENTRY_KEYS)
     */
    private function write(string $msg, string $state, string $event, int $at, string $dmId, string $code, string $text, string $senderRef, bool $once = false, array $more = []): bool
    {
        if (!in_array($state, Schema::MESSAGE_STATES, true) || !in_array($event, Schema::EVENT_KINDS, true)) {
            throw new \InvalidArgumentException('A message takes a state of Schema::MESSAGE_STATES and an event of Schema::EVENT_KINDS');
        }
        if (($state === Schema::MESSAGE_STATE_SKIPPED) && !in_array($code, Schema::BOX_PROBLEMS, true)) {
            throw new \InvalidArgumentException('A skipped message takes its reason, one of Schema::BOX_PROBLEMS');
        }
        $problem = in_array($state, self::PROBLEMS, true);
        $fields  = [
            Schema::MESSAGE_STATE      => $state,
            Schema::MESSAGE_ERROR_CODE => $problem ? $code : '',
            Schema::MESSAGE_ERROR_TEXT => $problem ? $text : '',
        ] + self::given([Schema::MESSAGE_DM_ID => $dmId, Schema::MESSAGE_SENDER_REF => $senderRef]);
        // when ISDS took it; a later lookup or delivery does not move it
        if ($event === Schema::EVENT_SUBMITTED) {
            $fields[Schema::MESSAGE_SENT_AT] = $at;
        }
        $entry = ['at' => $at, 'kind' => $event] + self::given(['dmID' => $dmId, 'code' => $code, 'message' => $text]) + $more;
        return $this->update($msg, $fields, $entry, [], $once ? [Schema::MESSAGE_STATE] : []);
    }

    /**
     * The one way a message item is written: $fields, $entry added to the timeline, $perKind joined to
     * it and $redate dating what it has (withFirstOfKind()). Only an item of this campaign is written -
     * an id edited into the record of another sending reaches no other campaign's message. Nothing is
     * written when the item shows the fields of $once already, nor when it shows every field and the
     * timeline gains nothing.
     * @param array<string, string|int>                         $fields  of Schema, the timeline aside
     * @param ?array<string, string|int>                        $entry   joins the timeline whatever it has
     * @param list<array{at: int, kind: string, code?: string}> $perKind
     * @param list<string>                                      $once    fields of $fields: the item shows
     *                                                                    them all already, nothing is written
     * @param list<array{at: int, kind: string, code?: string}> $redate
     * @return bool false = no such message item of this campaign - nothing written
     * @throws \RuntimeException not stored
     */
    private function update(string $msg, array $fields, ?array $entry, array $perKind, array $once, array $redate = []): bool
    {
        // the next write would drop an entry the reader does not take
        if (!is_null($entry) && !self::isEntry($entry)) {
            throw new \InvalidArgumentException('A timeline entry takes no value longer than ' . self::LONGEST_VALUE . ' bytes');
        }
        foreach ([...$perKind, ...$redate] as $e) {
            if (!self::isEntry($e) || !in_array($e['kind'], Schema::EVENT_KINDS, true)) {
                throw new \InvalidArgumentException('A timeline entry is one this class writes, of a kind of Schema::EVENT_KINDS');
            }
        }
        return $this->whileOpen($this->messageSlice, function () use ($msg, $fields, $entry, $perKind, $once, $redate): bool {
            $shown = $this->read($this->messageSlice, $msg, [...array_keys($fields), Schema::MESSAGE_CAMPAIGN, Schema::MESSAGE_EVENTS]);
            if (is_null($shown) || (strtolower($shown[Schema::MESSAGE_CAMPAIGN]) !== strtolower($this->campaign))) {
                return false;
            }
            if (($once !== []) && (array_diff_assoc(array_intersect_key($fields, array_flip($once)), $shown) === [])) {
                return true;
            }
            $had      = self::timeline($shown[Schema::MESSAGE_EVENTS]);
            $timeline = self::withFirstOfKind(is_null($entry) ? $had : [...$had, $entry], $perKind, true);
            $timeline = self::withFirstOfKind($timeline, $redate, false);
            if (($timeline === $had) && (array_diff_assoc($fields, $shown) === [])) {
                return true;
            }
            $fields[Schema::MESSAGE_EVENTS] = self::encoded($timeline);
            if (!$this->save($this->messageSlice, $msg, $fields, 'update', ItemContent::FLAG_JUST_STORE)) {
                throw new \RuntimeException("Message item $msg not stored, error " . ItemContent::lastErr());
            }
            return true;
        });
    }

    /**
     * $fields of the item $id as stored now, '' for one it has not; null = no such item in $slice
     * @param list<string> $fields
     * @return ?array<string, string>
     */
    private function read(string $slice, string $id, array $fields): ?array
    {
        // GetItemContent() keys the items by their ids in small letters
        $item = $this->content($id, $fields)[strtolower($id)] ?? null;
        if (!is_array($item) || (($item['u_slice_id......'][0]['value'] ?? '') !== strtolower($slice))) {
            return null;
        }
        $shown = [];
        foreach ($fields as $field) {
            $shown[$field] = (string) ($item[$field][0]['value'] ?? '');
        }
        return $shown;
    }

    /** @param array<string, string|int> $fields */
    private function save(string $slice, string $id, array $fields, string $mode, array $flags): bool
    {
        $content = new ItemContent();
        $content->setSliceID($slice);
        $content->setItemID($id);
        foreach ($fields as $field => $value) {
            $content->setAaValue($field, new AA_Value((string) $value));
        }
        // storeItem() leaves the error of an earlier call
        ItemContent::lastErr(0, '');
        return $this->store($content, $mode, $flags);
    }

    /**
     * The entries of the timeline $events. Only entries as this class writes them stay - one edited by
     * hand into something else, or a number no encoder writes back (1e400), would fail every later
     * write; a letter typed in another code page costs only itself.
     * @return list<array<string, string|int>>
     */
    private static function timeline(string $events): array
    {
        // depth 3: the list, an entry, its values - anything deeper is no entry
        $list = (strlen($events) <= self::LONGEST_TIMELINE) ? json_decode($events, true, 3, JSON_INVALID_UTF8_SUBSTITUTE) : null;
        return (is_array($list) && array_is_list($list)) ? array_values(array_filter($list, self::isEntry(...))) : [];
    }

    /**
     * $list with the time of each of $entries on the first entry of its kind - the time of the record,
     * which moves only to an earlier one ISDS told; the other keys of the entry dated stay. 0 (not told)
     * changes nothing. Joining, an entry whose kind $list lacks joins it; else it only moves a time told:
     * one not told may be that of another event.
     * @param list<array<string, string|int>>                   $list
     * @param list<array{at: int, kind: string, code?: string}> $entries
     * @return list<array<string, string|int>>
     */
    private static function withFirstOfKind(array $list, array $entries, bool $join): array
    {
        foreach ($entries as $entry) {
            $first = null;
            foreach ($list as $i => $had) {
                if (($had['kind'] ?? null) === $entry['kind']) {
                    $first = $i;
                    break;
                }
            }
            if (is_null($first)) {
                if ($join) {
                    $list[] = $entry;
                }
            } elseif (($entry['at'] !== 0) && ($join || (($list[$first]['at'] ?? 0) !== 0))) {
                $list[$first]['at'] = $entry['at'];
            }
        }
        return $list;
    }

    /**
     * The timeline of $list as stored: its first entry and the last EVENTS_KEPT. ASCII JSON: a cp1250
     * install keeps it intact.
     * @param list<array<string, string|int>> $list
     */
    private static function encoded(array $list): string
    {
        if (count($list) > self::EVENTS_KEPT + 1) {
            $list = [$list[0], ...array_slice($list, -self::EVENTS_KEPT)];
        }
        return json_encode($list, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** an entry of a timeline as written here: its keys, texts no longer than LONGEST_VALUE, whole numbers */
    private static function isEntry(mixed $entry): bool
    {
        return is_array($entry) && !array_is_list($entry) && (array_diff(array_keys($entry), self::ENTRY_KEYS) === [])
            && ($entry === array_filter($entry, static fn(mixed $v): bool => is_int($v) || (is_string($v) && (strlen($v) <= self::LONGEST_VALUE))));
    }

    /** @throws \InvalidArgumentException a message id, a code or a reference that is no word */
    private static function checkWords(string $dmId, string $code, string $senderRef): void
    {
        if ((preg_match('/^\d{0,20}\z/', $dmId) !== 1) || (preg_match(self::CODE, $code) !== 1) || !self::isRef($senderRef)) {
            throw new \InvalidArgumentException('A message id, a code and a reference are words, never a text');
        }
    }

    /** a reference of a message: a word, no longer than ISDS takes */
    private static function isRef(string $senderRef): bool
    {
        return (preg_match(self::CODE, $senderRef) === 1) && (strlen($senderRef) <= Message::MAX_REF);
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string> the ones that are not ''
     */
    private static function given(array $values): array
    {
        return array_filter($values, static fn(string $v): bool => $v !== '');
    }
}
