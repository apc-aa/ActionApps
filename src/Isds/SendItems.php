<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Later\BatchFailed;
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

    /** the keys of a timeline entry */
    private const ENTRY_KEYS = ['at', 'kind', 'dmID', 'code', 'message'];

    /** a word of Schema::BOX_PROBLEMS, a run's code or dmMessageStatus digits: no room for a text or a path */
    private const CODE = '/^[0-9A-Za-z._-]{0,64}\z/';

    /** the states that say what went wrong; any other clears the error of the message */
    private const PROBLEMS = [Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_SKIPPED];

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
     * @return bool created now; false = it is there already
     * @throws \RuntimeException not stored
     */
    public function create(array $row, string $environment, string $senderBox, int $at, bool $stopped = false): bool
    {
        $reason = $row['reason'];
        if (($reason !== '') && !in_array($reason, Schema::BOX_PROBLEMS, true)) {
            throw new \InvalidArgumentException('A recipient is skipped for one of Schema::BOX_PROBLEMS');
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
            Schema::MESSAGE_OFFICIAL    => $row['official'],
            Schema::MESSAGE_BOX         => $row['box'],
            Schema::MESSAGE_ENVIRONMENT => $environment,
            Schema::MESSAGE_SENDER_BOX  => $senderBox,
            Schema::MESSAGE_CAMPAIGN    => $this->campaign,
            Schema::MESSAGE_RECIPIENT   => $row['src'],
            // a view leaving test messages out (test = 0) would miss a message without the number
            Schema::MESSAGE_TEST        => 0,
            Schema::MESSAGE_STATE       => $state,
            Schema::MESSAGE_EVENTS      => self::appended('', $event),
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
     * @return bool false = no such message item, deleted by hand - nothing written
     * @throws \RuntimeException not stored
     */
    public function mark(string $msg, string $state, string $event, int $at, string $dmId = '', string $code = '', string $senderRef = '', bool $once = false): bool
    {
        if ((preg_match('/^\d{0,20}\z/', $dmId) !== 1) || (preg_match(self::CODE, $code) !== 1)
            || (preg_match(self::CODE, $senderRef) !== 1) || (strlen($senderRef) > Message::MAX_REF)) {
            throw new \InvalidArgumentException('A message id, a code and a reference are words, never a text');
        }
        return $this->write($msg, $state, $event, $at, $dmId, $code, '', $senderRef, once: $once);
    }

    /** mark() with what Outcome::of() made of an attempt, its text too */
    public function markOutcome(string $msg, Outcome $outcome, int $at): bool
    {
        return $this->write($msg, $outcome->state, $outcome->event, $at, $outcome->dmId, $outcome->isdsCode, $outcome->text, '');
    }

    /**
     * A message of unknown fate the list of sent ones holds: sent when it was tried - ISDS took it
     * within that request -, delivered as the list says; failed for good, its status the code, when
     * the antivirus check refused it. Once, as mark() $once.
     * @param int $triedAt the time of the attempt the record keeps
     */
    public function markFound(string $msg, SentMessage $sent, int $triedAt, int $at): bool
    {
        $refused  = ($sent->status === SentMessage::AV_REFUSED);
        $delivery = [
            Schema::MESSAGE_DM_STATUS    => $sent->status,
            Schema::MESSAGE_SENT_AT      => $triedAt,
            Schema::MESSAGE_DELIVERED_AT => $sent->deliveredAt,
            Schema::MESSAGE_ACCEPTED_AT  => $sent->acceptedAt,
        ];
        [$state, $event, $code] = $refused ? [Schema::MESSAGE_STATE_FAILED, Schema::EVENT_AV, (string) $sent->status] : [Schema::MESSAGE_STATE_SENT, Schema::EVENT_LOOKUP_FOUND, ''];
        return $this->write($msg, $state, $event, $at, $sent->dmId, $code, '', '', array_filter($delivery, is_int(...)), true);
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
            $shown = $this->read($this->campaignSlice, $this->campaign, Schema::CAMPAIGN_STATE);
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

    /** @return array<array-key, mixed> GetItemContent() of one field of the item $id */
    protected function content(string $id, string $field): array
    {
        return GetItemContent(new zids($id, 'l'), false, false, [$field]);
    }

    /**
     * $fn with $slice open. storeItem() reads the item before an update with the reading password of
     * the request - masked, the history would list every field as changed - and keeps what it stored
     * in AA_Items. A slice the run opened already stays open after it.
     * @param \Closure(): bool $fn
     */
    protected function whileOpen(string $slice, \Closure $fn): bool
    {
        return \AA_Credentials::singleton()->withSlicesOpen([$slice], $fn);
    }

    /**
     * The error fields hold the error of this state, cleared by one that is no problem; the timeline
     * keeps the earlier ones. $dmId and $senderRef left '' keep what the item has.
     * @param array<string, int> $more further fields of Schema
     */
    private function write(string $msg, string $state, string $event, int $at, string $dmId, string $code, string $text, string $senderRef, array $more = [], bool $once = false): bool
    {
        if (!in_array($state, Schema::MESSAGE_STATES, true) || !in_array($event, Schema::EVENT_KINDS, true)) {
            throw new \InvalidArgumentException('A message takes a state of Schema::MESSAGE_STATES and an event of Schema::EVENT_KINDS');
        }
        if (($state === Schema::MESSAGE_STATE_SKIPPED) && !in_array($code, Schema::BOX_PROBLEMS, true)) {
            throw new \InvalidArgumentException('A skipped message takes its reason, one of Schema::BOX_PROBLEMS');
        }
        $problem = in_array($state, self::PROBLEMS, true);
        $entry   = ['at' => $at, 'kind' => $event] + self::given(['dmID' => $dmId, 'code' => $code, 'message' => $text]);
        // the next write would drop an entry the reader does not take
        if (!self::isEntry($entry)) {
            throw new \InvalidArgumentException('A timeline entry takes no value longer than ' . self::LONGEST_VALUE . ' bytes');
        }
        $fields  = [
            Schema::MESSAGE_STATE      => $state,
            Schema::MESSAGE_ERROR_CODE => $problem ? $code : '',
            Schema::MESSAGE_ERROR_TEXT => $problem ? $text : '',
        ] + self::given([Schema::MESSAGE_DM_ID => $dmId, Schema::MESSAGE_SENDER_REF => $senderRef]) + $more;
        // when ISDS took it; a later lookup or delivery does not move it
        if ($event === Schema::EVENT_SUBMITTED) {
            $fields[Schema::MESSAGE_SENT_AT] = $at;
        }
        return $this->whileOpen($this->messageSlice, function () use ($msg, $entry, $fields, $state, $once): bool {
            if ($once && ($this->read($this->messageSlice, $msg, Schema::MESSAGE_STATE) === $state)) {
                return true;
            }
            $events = $this->read($this->messageSlice, $msg, Schema::MESSAGE_EVENTS);
            if (is_null($events)) {
                return false;
            }
            $fields[Schema::MESSAGE_EVENTS] = self::appended($events, $entry);
            if (!$this->save($this->messageSlice, $msg, $fields, 'update', ItemContent::FLAG_JUST_STORE)) {
                throw new \RuntimeException("Message item $msg not stored, error " . ItemContent::lastErr());
            }
            return true;
        });
    }

    /** $field of the item $id as stored now; null = no such item in $slice */
    private function read(string $slice, string $id, string $field): ?string
    {
        // GetItemContent() keys the items by their ids in small letters
        $item = $this->content($id, $field)[strtolower($id)] ?? null;
        if (!is_array($item) || (($item['u_slice_id......'][0]['value'] ?? '') !== strtolower($slice))) {
            return null;
        }
        return (string) ($item[$field][0]['value'] ?? '');
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
     * $events with $entry added, its first entry and the last EVENTS_KEPT kept. ASCII JSON: a cp1250
     * install keeps it intact. Only entries as this class writes them stay - one edited by hand into
     * something else, or a number no encoder writes back (1e400), would fail every later write; a
     * letter typed in another code page costs only itself.
     * @param array<string, string|int> $entry
     */
    private static function appended(string $events, array $entry): string
    {
        // depth 3: the list, an entry, its values - anything deeper is no entry
        $list = (strlen($events) <= self::LONGEST_TIMELINE) ? json_decode($events, true, 3, JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $list = (is_array($list) && array_is_list($list)) ? array_values(array_filter($list, self::isEntry(...))) : [];
        $list[] = $entry;
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

    /**
     * @param array<string, string> $values
     * @return array<string, string> the ones that are not ''
     */
    private static function given(array $values): array
    {
        return array_filter($values, static fn(string $v): bool => $v !== '');
    }
}
