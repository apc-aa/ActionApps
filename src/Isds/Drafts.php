<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
use AA_Items;
use AA_Value;
use ItemContent;
use zids;

/**
 * The drafts of a mass sending a page writes: the letter and whom it goes to, nothing of a sending.
 * A draft changes only while it is one - not queued, with no run -, under the lock of the campaign
 * that queueing holds while it freezes the letter. Written only through storeItem(): the history of
 * the slice records each change, so a field the form did not change is not written, and a save that
 * changes nothing writes nothing (an autosave every few seconds).
 * A new draft takes its id from the nonce of the form shown: every save of that form - autosaves, a
 * double click - writes the one item, and a stale form never makes a second one. Its author is the
 * login of the request: storeItem() writes it.
 * Two saves of one draft at once - an autosave and a click, two tabs - do not wait for each other: the
 * second gets BUSY, as queueing does.
 */
class Drafts
{
    /** bytes of the body: room for pictures as data URIs */
    public const MAX_BODY = 8 * 1024 * 1024;

    /** the draft is saved - or was already as posted */
    public const SAVED = 'SAVED';

    /** what INPUT names: the field of the form that cannot be taken; NONCE, a hidden one, is told as none */
    public const SUBJECT    = 'subject';
    public const TO_HANDS   = 'to_hands';
    public const BODY       = 'body';
    /** a choice of recipients the page does not offer */
    public const RECIPIENTS = 'recipients';
    /** more items chosen or left out one by one than Setup::MAX_RECIPIENTS */
    public const TOO_MANY   = 'too_many';
    public const NONCE      = '';

    /** storeItem(): invalidate the pages of the campaign slice, no feeding, no events */
    private const FLAGS = [true, false, false, false];

    /**
     * Saves what the form posts into the draft $campaign, or into a new one. A field the form does not
     * post stays as it is; the recipients are written only with isds_membership.
     * Fields: isds_subject, isds_to_hands, isds_body (HTML, stored as posted - escape it wherever shown),
     * isds_membership, isds_types[], isds_include and isds_exclude (item ids separated by "-": thousands
     * of fields would be cut by max_input_vars), and for a new draft isds_nonce (32 hexadecimal digits).
     * @param string                  $campaign long id in small letters of an item of the campaign slice;
     *                                            '' = a new draft
     * @param array<array-key, mixed> $post     the form as posted
     * @return array{code: string, campaign: string, field: string} code: SAVED, Actions::INPUT (field: what
     *                                                              cannot be taken), Actions::EXPIRED (no
     *                                                              session), SendStart::UNKNOWN, NOT_DRAFT,
     *                                                              ALREADY or BUSY; campaign: the draft -
     *                                                              of a new one too, so that the page goes
     *                                                              on with it -, '' for UNKNOWN and with no
     *                                                              id known
     * @throws \RuntimeException not stored
     */
    public function save(Setup $setup, string $campaign, array $post): array
    {
        $id = $campaign;
        if ($campaign === '') {
            $nonce = $post['isds_nonce'] ?? null;
            if (!is_string($nonce) || (preg_match('/^[0-9a-f]{32}\z/', $nonce) !== 1)) {
                return self::told(Actions::INPUT, '', self::NONCE);
            }
            $id = self::newId($setup, $nonce);
            if ($id === '') {
                return self::told(Actions::EXPIRED, '');
            }
        }
        $fields = $this->fields($setup, $post);
        if (is_string($fields)) {
            return self::told(Actions::INPUT, $id, $fields);
        }
        if (!$this->lock(SendRun::lockName($id))) {
            return self::told(SendStart::BUSY, $id);
        }
        try {
            return $this->whileOpen($setup->campaignSlice, fn(): array => $this->write($setup, $id, $campaign === '', $fields));
        } finally {
            $this->unlock(SendRun::lockName($id));
        }
    }

    /**
     * The id of the draft the form with $nonce makes: the same for every save of that form, another for
     * another session - and never a token of a form, which the same key signs under another prefix.
     * An id as AA makes them (string2id()): no byte the packed id would lose. '' without a session: the
     * id would be known to anyone.
     */
    public static function newId(Setup $setup, string $nonce): string
    {
        $key = FormToken::get("isds-id:new:{$setup->bindingKey()}:$nonce");

        return ($key === '') ? '' : string2id($key);
    }

    /**
     * The campaign item as stored now - the item cache of the request may hold it as it was before
     * another request queued it; null = none
     */
    public static function stored(string $id): ?ItemContent
    {
        $content = AA_Items::getItem(new zids($id, 'l'), null, true)?->getItemContent();
        return ($content instanceof ItemContent) ? $content : null;
    }

    /**
     * The item, when it may change as a draft of the page - saving and queueing ask it alike: an active
     * item of the campaign slice, a draft, never queued
     * @param ?ItemContent     $item       stored()
     * @param \Closure(): bool $queuedOnce the campaign has a run, whatever its item shows; asked of a draft only
     * @return ItemContent|string the item, or SendStart::UNKNOWN, NOT_DRAFT or ALREADY
     */
    public static function draftOf(Setup $setup, ?ItemContent $item, \Closure $queuedOnce): ItemContent|string
    {
        if (is_null($item) || (strtolower($item->getSliceID()) !== $setup->campaignSlice) || !$item->isActive()) {
            return SendStart::UNKNOWN;
        }
        if ((string) $item->getValue(Schema::CAMPAIGN_STATE) !== Schema::CAMPAIGN_STATE_DRAFT) {
            return SendStart::NOT_DRAFT;
        }

        return $queuedOnce() ? SendStart::ALREADY : $item;
    }

    /**
     * Under the lock: a new draft stored, or the draft checked as stored now and its changes written
     * @param array<string, AA_Value> $fields
     * @return array{code: string, campaign: string, field: string}
     */
    private function write(Setup $setup, string $id, bool $new, array $fields): array
    {
        $queuedOnce = fn(): bool => $this->runExists($id);
        // a stale form of a draft queued makes nothing: not the draft again, deleted for good under its run
        $queued = $new && $queuedOnce();
        if ($new && !$queued) {
            $content = self::content($setup, $id, [
                'status_code.....'    => new AA_Value(\SC_ACTIVE),
                Schema::CAMPAIGN_STATE => new AA_Value(Schema::CAMPAIGN_STATE_DRAFT),
            ] + $fields);
            if ($this->store($content, 'insert_if_new', self::FLAGS)) {
                return self::told(self::SAVED, $id);
            }
            if (ItemContent::lastErr() !== \ITEMCONTENT_ERROR_DUPLICATE) {
                throw new \RuntimeException("Draft $id not stored, error " . ItemContent::lastErr());
            }
            // saved by this form before: changed as a draft of the page, never moved into its slice
            // from another, nor revived from the trash or from a sending
        }
        $stored = $this->campaign($id);
        // in the trash it is told as any other draft there
        if ($queued && is_null($stored)) {
            return self::told(SendStart::ALREADY, $id);
        }
        $item = self::draftOf($setup, $stored, $queuedOnce);
        if (is_string($item)) {
            // no draft of the page under this id: the page shows none
            return self::told($item, ($item === SendStart::UNKNOWN) ? '' : $id);
        }
        $changed = array_filter($fields, static function (AA_Value $value, string $field) use ($item): bool {
            $had = $item->getAaValue($field);
            // a body the admin stored as plain text would go as plain text
            $plain = ($field === Schema::CAMPAIGN_BODY) && (($had->getFlag() & FLAG_HTML) === 0);
            return ((string) $had->getValue() !== (string) $value->getValue()) || $plain;
        }, ARRAY_FILTER_USE_BOTH);
        if (($changed !== []) && !$this->store(self::content($setup, $id, $changed), 'update', self::FLAGS)) {
            throw new \RuntimeException("Draft $id not stored, error " . ItemContent::lastErr());
        }

        return self::told(self::SAVED, $id);
    }

    /**
     * The fields of the letter the form posts, as they are stored
     * @param array<array-key, mixed> $post
     * @return array<string, AA_Value>|string the field of the form that cannot be taken
     */
    private function fields(Setup $setup, array $post): array|string
    {
        $fields = [];
        foreach ([self::SUBJECT => [Schema::CAMPAIGN_SUBJECT, Message::MAX_ANNOTATION], self::TO_HANDS => [Schema::CAMPAIGN_TO_HANDS, Message::MAX_TO_HANDS]] as $name => [$field, $max]) {
            if (!array_key_exists("isds_$name", $post)) {
                continue;
            }
            $value = $post["isds_$name"];
            if (!is_string($value)) {
                return $name;
            }
            try {
                // what ISDS takes: sending checks it again
                $fields[$field] = new AA_Value(Text::check($name, $value, $max));
            } catch (\InvalidArgumentException) {
                return $name;
            }
        }
        if (array_key_exists('isds_body', $post)) {
            $body = $post['isds_body'];
            if (!is_string($body) || (strlen($body) > self::MAX_BODY) || !mb_check_encoding($body, 'UTF-8')) {
                return self::BODY;
            }
            // HtmlBody cleans it when the letter is made
            $fields[Schema::CAMPAIGN_BODY] = new AA_Value($body, FLAG_HTML);
        }
        if (array_key_exists('isds_membership', $post)) {
            $filter = self::filter($setup, $post);
            if (in_array($filter, [self::RECIPIENTS, self::TOO_MANY], true)) {
                return $filter;
            }
            $fields[Schema::CAMPAIGN_FILTER] = new AA_Value($filter);
        }

        return $fields;
    }

    /**
     * The recipient filter of the form, as the campaign stores it
     * @param array<array-key, mixed> $post
     * @return string the filter; RECIPIENTS = not one the page offers, TOO_MANY = too many ids
     */
    private static function filter(Setup $setup, array $post): string
    {
        $lists = [];
        foreach (['include', 'exclude'] as $list) {
            $posted = $post["isds_$list"] ?? '';
            if (!is_string($posted)) {
                return self::RECIPIENTS;
            }
            // what fromJson() would refuse anyway, before it is split into a list
            if (strlen($posted) > RecipientFilter::MAX_BYTES) {
                return self::TOO_MANY;
            }
            $lists[$list] = ($posted === '') ? [] : explode('-', $posted);
            if (count($lists[$list]) > Setup::MAX_RECIPIENTS) {
                return self::TOO_MANY;
            }
        }
        $filter = ['types' => $post['isds_types'] ?? [], 'membership' => $post['isds_membership']] + $lists;
        try {
            // one reader of a filter: the one sending reads it with
            return RecipientFilter::fromJson(json_encode($filter, JSON_THROW_ON_ERROR), $setup)->toJson();
        } catch (\InvalidArgumentException|\JsonException) {
            return self::RECIPIENTS;
        }
    }

    /** @param array<string, AA_Value> $fields */
    private static function content(Setup $setup, string $id, array $fields): ItemContent
    {
        $content = new ItemContent();
        // storeItem() moves an item into the slice given: always the slice of the page, checked before an update
        $content->setSliceID($setup->campaignSlice);
        $content->setItemID($id);
        foreach ($fields as $field => $value) {
            $content->setAaValue($field, $value);
        }
        // storeItem() leaves the error of an earlier call
        ItemContent::lastErr(0, '');

        return $content;
    }

    /** @return array{code: string, campaign: string, field: string} */
    private static function told(string $code, string $campaign, string $field = ''): array
    {
        return ['code' => $code, 'campaign' => $campaign, 'field' => $field];
    }

    // ------------------------------------------------------------ what tests replace

    /** @param list<bool> $flags see ItemContent::storeItem() */
    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        return $content->storeItem($mode, $flags) !== false;
    }

    /** stored() */
    protected function campaign(string $id): ?ItemContent
    {
        return self::stored($id);
    }

    /** the campaign was queued once: it never changes again, whatever its item shows */
    protected function runExists(string $campaign): bool
    {
        return SendRun::existsFor($campaign);
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

    /**
     * $fn with the slice open: storeItem() reads the item before an update with the reading password of
     * the request - masked, the history would list every field as changed
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    protected function whileOpen(string $slice, \Closure $fn): mixed
    {
        return \AA_Credentials::singleton()->withSlicesOpen([$slice], $fn);
    }
}
