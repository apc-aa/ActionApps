<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
use AA\Upload\UploadPolicy;
use AA\Util\FormToken;
use AA_Items;
use AA_Slice;
use AA_Value;
use Files;
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
 * Attachments go into the upload directory of the campaign slice, as the editor of the slice puts
 * them there: public to whoever knows the URL. A file is taken only when both the editor would
 * upload it (UploadPolicy) and a data message carries it (AttachmentPolicy), by its content, never
 * by what the browser says; and only into a draft that may change - checked first, before a file is
 * read. Removing one drops its URL from the draft only: a copy of the draft may share the file.
 * A copy (copy()) is a new draft of the letter of any campaign of the page - to send it again, to
 * those its sending left unsent, or to one recipient once more -, made once for the form shown as a new
 * draft is.
 */
class Drafts
{
    /** bytes of the body: room for pictures as data URIs */
    public const MAX_BODY = 8 * 1024 * 1024;

    /** the draft is saved - or was already as posted */
    public const SAVED = 'SAVED';
    /** the letter is saved, the recipients the form chose are not: the filter stored is not the one its
     *  page showed (another window saved another since), or an item it picked is gone */
    public const FILTER_CHANGED = 'FILTER_CHANGED';
    /** the copy is made - or was, by the same form before */
    public const CLONED = 'CLONED';

    /** what a copy takes: the whole campaign, only the recipients its sending left unsent, or one
     *  recipient - copy() takes ONE as one(), naming the message */
    public const ALL    = 'all';
    public const UNSENT = 'unsent';
    public const ONE    = 'one';

    /** what INPUT names: the field of the form that cannot be taken; NONCE, a hidden one, is told as none */
    public const SUBJECT    = 'subject';
    public const TO_HANDS   = 'to_hands';
    public const BODY       = 'body';
    /** a choice of recipients the page does not offer */
    public const RECIPIENTS = 'recipients';
    /** more items chosen or left out one by one than Setup::MAX_RECIPIENTS */
    public const TOO_MANY   = 'too_many';
    public const NONCE      = '';
    /** the attachments are not posted as isds_files[] and isds_remove[] (URLs) */
    public const FILES          = 'files';
    /** more attachments than a data message carries beside the letter */
    public const TOO_MANY_FILES = 'too_many_files';
    /** what INPUT names of one file, with its name: a type the editor does not upload or a data message
     *  does not carry (archives, web pages, XML) */
    public const FILE_TYPE      = 'file_type';
    /** its content is not of the type its extension names */
    public const FILE_CONTENT   = 'file_content';
    /** larger than PHP takes or than a data message carries */
    public const FILE_SIZE      = 'file_size';
    public const FILE_EMPTY     = 'file_empty';
    /** it did not arrive whole */
    public const FILE_LOST      = 'file_lost';

    /** storeItem(): invalidate the pages of the campaign slice, no feeding, no events */
    private const FLAGS = [true, false, false, false];

    /** @param \Closure(): Recipients $resolver as Gate::resolver() - tells a pick of an active item of the source slice */
    public function __construct(private \Closure $resolver) {}

    /**
     * Saves what the form posts into the draft $campaign, or into a new one. A field the form does not
     * post stays as it is; the recipients are written only with isds_membership, and only when the form
     * carries the filter stored now as the page read it (isds_filter_seen, filterSeen()): a page that
     * could not read it shows it empty, a stale one shows another - the letter is saved then, the
     * filter stays and the save tells FILTER_CHANGED, as for a pick of an item gone since.
     * Fields: isds_subject, isds_to_hands, isds_body (HTML, stored as posted - escape it wherever shown),
     * isds_membership, isds_types[], isds_include and isds_exclude (item ids separated by "-": thousands
     * of fields would be cut by max_input_vars), isds_pick (one item picked by a button into the lists
     * posted, RecipientFilter::picked() - only with isds_membership), isds_filter_seen, isds_remove[]
     * (URLs of attachments to drop), and for a new draft isds_nonce (32 hexadecimal digits). Files:
     * isds_files[], added to the attachments; a file posted under another name is refused - the insert
     * function of the field (fil) would take it by rules of its own -, an empty file input of another
     * name is not.
     * Anything refused writes nothing and leaves no file.
     * @param string                  $campaign long id in small letters of an item of the campaign slice;
     *                                            '' = a new draft
     * @param array<array-key, mixed> $post     the form as posted
     * @param array<array-key, mixed> $files    the files as posted ($_FILES)
     * @return array{code: string, campaign: string, field: string, file: string, refiltered: bool} code: SAVED,
     *         FILTER_CHANGED, Actions::INPUT (field: what cannot be taken; file: the name of the file refused, as it
     *         would be stored, '' for none), Actions::EXPIRED (no session), SendStart::UNKNOWN, NOT_DRAFT, ALREADY or
     *         BUSY; campaign: the draft - of a new one too, so that the page goes on with it -, '' for
     *         UNKNOWN and with no id known; refiltered: the filter stored now is another than the one the
     *         form showed (isds_filter_seen) - a new draft too -, so the lists of its recipients are others
     * @throws \RuntimeException not stored; an upload the server could not take
     */
    public function save(Setup $setup, string $campaign, array $post, array $files = []): array
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
        $read = $this->fields($setup, $post);
        if (is_string($read)) {
            return self::told(Actions::INPUT, $id, $read);
        }
        [$fields, $lost] = $read;
        $attached = self::attached($post, $files);
        if ($attached['field'] !== '') {
            return self::told(Actions::INPUT, $id, $attached['field'], $attached['file']);
        }
        if (!$this->lock(SendRun::lockName($id))) {
            return self::told(SendStart::BUSY, $id);
        }
        try {
            $seen = is_string($post['isds_filter_seen'] ?? null) ? $post['isds_filter_seen'] : '';

            return $this->whileOpen($setup->campaignSlice, fn(): array => $this->write($setup, $id, $campaign === '', $fields, $lost, $attached, $seen));
        } finally {
            $this->unlock(SendRun::lockName($id));
        }
    }

    /**
     * What tells the stored filter $stored ('' = none), so that a form can show which one its page read:
     * the hidden field isds_filter_seen of the form of a draft (Gate::fields())
     */
    public static function filterSeen(string $stored): string
    {
        return hash('sha256', $stored);
    }

    /**
     * The id of the draft the form with $nonce makes: the same for every save of that form, another for
     * another session - and never a token of a form, which the same key signs under another prefix.
     * An id as AA makes them (string2id()): no byte the packed id would lose. '' without a session: the
     * id would be known to anyone.
     */
    public static function newId(Setup $setup, string $nonce): string
    {
        return self::derivedId("new:{$setup->bindingKey()}:$nonce");
    }

    /**
     * The id of the copy of $source the form with $nonce makes - the same for a double click, another
     * for another source, scope or session; as newId()
     */
    public static function copyId(Setup $setup, string $source, string $scope, string $nonce): string
    {
        return self::derivedId("clone:{$setup->bindingKey()}:$source:$scope:$nonce");
    }

    /** the scope of copy() to the recipient of message $msg (a long id in small letters) alone */
    public static function one(string $msg): string
    {
        return self::ONE . ":$msg";
    }

    /**
     * A copy of the campaign $source as a new draft of the page: its letter - subject, "to hands", the
     * body, the attachments, whose files it shares with the source - and whom it goes to: the filter the
     * source stores, or for UNSENT and one() the items $items. It names its source (Schema::CAMPAIGN_TEMPLATE);
     * nothing of a sending is copied - the state, the fields a run writes. Its id comes from the nonce of
     * the form (copyId()): a double click makes the one copy; a stale form makes none again - of a copy
     * queued since it tells so, as a save does. A copy to one recipient takes its message into the id: one
     * form shown may copy for each message of a list.
     * The source of ALL is an active item, of UNSENT and one() one in any bin - a campaign put in the trash
     * still shows its sending. Not logged here: the caller logs the copy made.
     * @param string                  $source long id in small letters of an item of the campaign slice
     * @param string                  $scope  ALL, UNSENT or one()
     * @param array<array-key, mixed> $post   the form as posted: isds_nonce (32 hexadecimal digits)
     * @param list<string>            $items  UNSENT: the source items of the recipients the sending left unsent
     *                                        (SendRun::unsentSources()); one(): the source item of the recipient
     * @return array{code: string, campaign: string, made: bool} code: CLONED, Actions::INPUT (the scope or
     *         the nonce), Actions::EXPIRED (no session), SendStart::UNKNOWN, NOT_DRAFT, ALREADY or BUSY;
     *         campaign: the copy, '' for UNKNOWN, INPUT and EXPIRED; made: written now, not by the same form before
     * @throws \RuntimeException|\InvalidArgumentException not stored; $items no filter can hold
     */
    public function copy(Setup $setup, string $source, string $scope, array $post, array $items = []): array
    {
        $nonce  = $post['isds_nonce'] ?? null;
        $scoped = in_array($scope, [self::ALL, self::UNSENT], true) || (preg_match('/^' . self::ONE . ':[0-9a-f]{32}\z/', $scope) === 1);
        if (!$scoped || !is_string($nonce) || (preg_match('/^[0-9a-f]{32}\z/', $nonce) !== 1)) {
            return self::copied(Actions::INPUT, '');
        }
        $id = self::copyId($setup, $source, $scope, $nonce);
        if ($id === '') {
            return self::copied(Actions::EXPIRED, '');
        }
        if (!$this->lock(SendRun::lockName($id))) {
            return self::copied(SendStart::BUSY, $id);
        }
        try {
            return $this->whileOpen($setup->campaignSlice, fn(): array => $this->writeCopy($setup, $source, $scope, $id, $items));
        } finally {
            $this->unlock(SendRun::lockName($id));
        }
    }

    /** an id as AA makes them (string2id()), from the key of the session; '' without a session */
    private static function derivedId(string $purpose): string
    {
        $key = FormToken::get("isds-id:$purpose");

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
     * @param bool   $lost the form picked an item gone since: its filter is not in $fields
     * @param array{remove: list<string>, uploads: list<array{name: string, tmp: string}>, field: string, file: string} $attached
     * @param string $seen isds_filter_seen of the form
     * @return array{code: string, campaign: string, field: string, file: string, refiltered: bool}
     */
    private function write(Setup $setup, string $id, bool $new, array $fields, bool $lost, array $attached, string $seen): array
    {
        $queuedOnce = fn(): bool => $this->runExists($id);
        $uploads    = $attached['uploads'];
        // a stale form of a draft queued makes nothing: not the draft again, deleted for good under its run
        $queued = $new && $queuedOnce();
        // files go only into a draft that takes them: the form that made it already saves as into any draft
        $made = ($new && !$queued && ($uploads !== [])) ? $this->campaign($id) : null;
        if ($new && !$queued && is_null($made)) {
            $refused = $this->refusal([], $uploads);
            if ($refused) {
                return self::told(Actions::INPUT, $id, ...$refused);
            }
            [$written, $code] = self::overStored($fields, $lost, '', $seen);
            $moved            = $this->moveIn($setup, $uploads);
            $content          = self::content($setup, $id, [
                'status_code.....'    => new AA_Value(\SC_ACTIVE),
                Schema::CAMPAIGN_STATE => new AA_Value(Schema::CAMPAIGN_STATE_DRAFT),
            ] + $written + (($moved === []) ? [] : [Schema::CAMPAIGN_FILES => new AA_Value(array_values($moved))]));
            if ($this->storeWith($content, 'insert_if_new', $moved)) {
                return self::told($code, $id, refiltered: true);
            }
            if ((ItemContent::lastErr() !== \ITEMCONTENT_ERROR_DUPLICATE) || ($moved !== [])) {
                throw new \RuntimeException("Draft $id not stored, error " . ItemContent::lastErr());
            }
            // saved by this form before: changed as a draft of the page, never moved into its slice
            // from another, nor revived from the trash or from a sending
        }
        $stored = $made ?? $this->campaign($id);
        // in the trash it is told as any other draft there
        if ($queued && is_null($stored)) {
            return self::told(SendStart::ALREADY, $id);
        }
        $item = self::draftOf($setup, $stored, $queuedOnce);
        if (is_string($item)) {
            // no draft of the page under this id: the page shows none
            return self::told($item, ($item === SendStart::UNKNOWN) ? '' : $id);
        }
        $had     = self::attachmentsOf($item);
        $kept    = array_values(array_diff($had, $attached['remove']));
        $refused = $this->refusal($kept, $uploads);
        if ($refused) {
            return self::told(Actions::INPUT, $id, ...$refused);
        }
        [$fields, $code] = self::overStored($fields, $lost, (string) $item->getValue(Schema::CAMPAIGN_FILTER), $seen);
        $changed         = array_filter($fields, static function (AA_Value $value, string $field) use ($item): bool {
            $had = $item->getAaValue($field);
            // a body the admin stored as plain text would go as plain text
            $plain = ($field === Schema::CAMPAIGN_BODY) && (($had->getFlag() & FLAG_HTML) === 0);
            return ((string) $had->getValue() !== (string) $value->getValue()) || $plain;
        }, ARRAY_FILTER_USE_BOTH);
        $moved = $this->moveIn($setup, $uploads);
        if (($kept !== $had) || ($moved !== [])) {
            $changed[Schema::CAMPAIGN_FILES] = new AA_Value([...$kept, ...array_values($moved)]);
        }
        if (($changed !== []) && !$this->storeWith(self::content($setup, $id, $changed), 'update', $moved)) {
            throw new \RuntimeException("Draft $id not stored, error " . ItemContent::lastErr());
        }

        // against the filter the page showed, not the one before this write: a second post of the same
        // form - a double click, a pick without JavaScript - finds its choice stored by the first
        $filter = $changed[Schema::CAMPAIGN_FILTER] ?? $item->getAaValue(Schema::CAMPAIGN_FILTER);

        return self::told($code, $id, refiltered: !hash_equals(self::filterSeen((string) $filter->getValue()), $seen));
    }

    /**
     * Under the lock of the copy: the copy stored, or what became of the one the same form made before
     * @param list<string> $items
     * @return array{code: string, campaign: string, made: bool}
     */
    private function writeCopy(Setup $setup, string $source, string $scope, string $id, array $items): array
    {
        $queuedOnce = fn(): bool => $this->runExists($id);
        $made       = $this->campaign($id);
        if (!is_null($made)) {
            // an item that only shares the id is never told as the copy
            if (strtolower((string) $made->getValue(Schema::CAMPAIGN_TEMPLATE)) !== $source) {
                return self::copied(SendStart::UNKNOWN, '');
            }
            // a double click, or a stale form of a copy queued or put in the trash since: nothing made again
            $draft = self::draftOf($setup, $made, $queuedOnce);

            return is_string($draft) ? self::copied($draft, ($draft === SendStart::UNKNOWN) ? '' : $id) : self::copied(self::CLONED, $id);
        }
        // queued, then deleted for good: not made again under its run
        if ($queuedOnce()) {
            return self::copied(SendStart::ALREADY, $id);
        }
        $item = $this->campaign($source);
        if (is_null($item) || (strtolower($item->getSliceID()) !== $setup->campaignSlice) || (($scope === self::ALL) && !$item->isActive())) {
            return self::copied(SendStart::UNKNOWN, '');
        }
        $filter = ($scope === self::ALL) ? (string) $item->getValue(Schema::CAMPAIGN_FILTER) : RecipientFilter::ofItems($items)->toJson();
        $letter = array_filter([
            Schema::CAMPAIGN_SUBJECT  => $item->getAaValue(Schema::CAMPAIGN_SUBJECT),
            Schema::CAMPAIGN_TO_HANDS => $item->getAaValue(Schema::CAMPAIGN_TO_HANDS),
            // with its flag: a body stored as plain text goes as plain text
            Schema::CAMPAIGN_BODY     => $item->getAaValue(Schema::CAMPAIGN_BODY),
            Schema::CAMPAIGN_FILES    => new AA_Value(self::attachmentsOf($item)),
            // none stored: the copy too is refused sending till its recipients are chosen
            Schema::CAMPAIGN_FILTER   => ($filter === '') ? new AA_Value() : new AA_Value($filter),
        ], static fn(AA_Value $value): bool => !$value->isEmpty());
        $content = self::content($setup, $id, [
            'status_code.....'       => new AA_Value(\SC_ACTIVE),
            Schema::CAMPAIGN_STATE    => new AA_Value(Schema::CAMPAIGN_STATE_DRAFT),
            Schema::CAMPAIGN_TEMPLATE => new AA_Value($source),
        ] + $letter);
        if (!$this->store($content, 'insert_if_new', self::FLAGS)) {
            throw new \RuntimeException("Copy $id not stored, error " . ItemContent::lastErr());
        }

        return self::copied(self::CLONED, $id, true);
    }

    /**
     * What to write over the filter $stored now, and what the save tells: the filter the form posts only
     * when the form showed $stored (filterSeen())
     * @param array<string, AA_Value> $fields
     * @param bool                    $lost   see write()
     * @return array{array<string, AA_Value>, string} the fields; SAVED or FILTER_CHANGED
     */
    private static function overStored(array $fields, bool $lost, string $stored, string $seen): array
    {
        $posted = $fields[Schema::CAMPAIGN_FILTER] ?? null;
        if (is_null($posted) || hash_equals(self::filterSeen($stored), $seen)) {
            return [$fields, $lost ? self::FILTER_CHANGED : self::SAVED];
        }
        // the choice stored already - a second click before the page showed the first - loses nothing
        return [array_diff_key($fields, [Schema::CAMPAIGN_FILTER => true]), ((string) $posted->getValue() === $stored) ? self::SAVED : self::FILTER_CHANGED];
    }

    /** @return list<string> attachments() of the campaign */
    private static function attachmentsOf(ItemContent $item): array
    {
        return self::attachments($item->getValuesArray(Schema::CAMPAIGN_FILES));
    }

    /**
     * The URLs of the attachments of a campaign, of the values of its field; an empty value - of a field
     * left empty in the editor of the slice - is no attachment
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function attachments(array $values): array
    {
        return array_values(array_filter(array_map(static fn(mixed $url): string => trim((string) $url), $values), static fn(string $url): bool => $url !== ''));
    }

    /**
     * store(), the files moved in for it removed again when it does not store - also when it throws
     * @param array<string, string> $moved moveIn()
     */
    private function storeWith(ItemContent $content, string $mode, array $moved): bool
    {
        try {
            $stored = $this->store($content, $mode, self::FLAGS);
        } catch (\Throwable $e) {
            $this->discard(array_keys($moved));
            throw $e;
        }
        if (!$stored) {
            $this->discard(array_keys($moved));
        }

        return $stored;
    }

    /**
     * The extensions of the files a draft takes: what the editor of the slice uploads and a data message carries
     * @return list<string>
     */
    public static function attachable(): array
    {
        return array_values(array_filter(array_keys(UploadPolicy::USER_TYPES), AttachmentPolicy::allows(...)));
    }

    /** bytes one attachment may have: what PHP takes of a file and of a request, and what a data message carries */
    public static function maxFileBytes(): int
    {
        $limits = [ini_parse_quantity((string) ini_get('upload_max_filesize')), ini_parse_quantity((string) ini_get('post_max_size')), Message::MAX_BYTES];

        // 0 = no limit of PHP
        return min(array_filter($limits, static fn(int $bytes): bool => $bytes > 0));
    }

    /**
     * A field of $_FILES holds an upload - an <input type="file"> left empty holds none
     * @param mixed $posted one entry of $_FILES
     */
    public static function carriesFile(mixed $posted): bool
    {
        $errors = is_array($posted) ? ($posted['error'] ?? null) : UPLOAD_ERR_NO_FILE;
        if (!is_array($errors)) {
            return $errors !== UPLOAD_ERR_NO_FILE;
        }
        $carries = false;
        array_walk_recursive($errors, static function (mixed $error) use (&$carries): void {
            $carries = $carries || ($error !== UPLOAD_ERR_NO_FILE);
        });

        return $carries;
    }

    /**
     * The files the form posts and the attachments it drops, checked as far as no file is read
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     * @return array{remove: list<string>, uploads: list<array{name: string, tmp: string}>, field: string, file: string}
     *         field: what INPUT names, '' = taken; file: the name of the file refused
     * @throws \RuntimeException the server could not take an upload - no temporary directory, a full disk
     */
    private static function attached(array $post, array $files): array
    {
        $refused = static fn(string $field, string $file = ''): array => ['remove' => [], 'uploads' => [], 'field' => $field, 'file' => $file];
        $remove  = $post['isds_remove'] ?? [];
        if (!is_array($remove) || (array_filter($remove, static fn(mixed $url): bool => !is_string($url)) !== [])) {
            return $refused(self::FILES);
        }
        // the insert function of the field (fil) would take a file posted under another name by rules of its own
        foreach (array_diff_key($files, ['isds_files' => true]) as $other) {
            if (self::carriesFile($other)) {
                return $refused(self::FILES);
            }
        }
        // isds_files[] as PHP gives it: each part a list; isds_files without [] gives single values
        $columns = [];
        foreach (['name', 'tmp_name', 'error', 'size'] as $part) {
            $value          = $files['isds_files'][$part] ?? [];
            $columns[$part] = is_array($value) ? $value : [$value];
        }
        $uploads = [];
        foreach ($columns['name'] as $i => $name) {
            [$tmp, $error, $size] = [$columns['tmp_name'][$i] ?? null, $columns['error'][$i] ?? null, $columns['size'][$i] ?? null];
            if (!is_string($name) || !is_string($tmp) || !is_int($error) || !is_int($size)) {
                return $refused(self::FILES);
            }
            // a file input left empty
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            // as the file would be stored and named to the recipient - and as INPUT names it, in safe letters
            $stored = UploadPolicy::safeName(Files::escape($name));
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($size > self::maxFileBytes())) {
                return $refused(self::FILE_SIZE, $stored);
            }
            if ($error === UPLOAD_ERR_PARTIAL) {
                return $refused(self::FILE_LOST, $stored);
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new \RuntimeException("The server could not take an upload, error $error");
            }
            if (!in_array(strtolower(pathinfo($stored, PATHINFO_EXTENSION)), self::attachable(), true)) {
                return $refused(self::FILE_TYPE, $stored);
            }
            $uploads[] = ['name' => $stored, 'tmp' => $tmp];
        }
        if (count($uploads) >= Message::MAX_FILES) {
            return $refused(self::TOO_MANY_FILES);
        }

        return ['remove' => array_values($remove), 'uploads' => $uploads, 'field' => '', 'file' => ''];
    }

    /**
     * Why the uploads cannot be attached beside the attachments the draft keeps - each read once and
     * checked by its content
     * @param list<string>                             $kept
     * @param list<array{name: string, tmp: string}> $uploads
     * @return array{string, string}|null the field and the file INPUT names; null = all may be attached
     */
    private function refusal(array $kept, array $uploads): ?array
    {
        // the letter is the first file of the message
        if (count($kept) + count($uploads) >= Message::MAX_FILES) {
            return [self::TOO_MANY_FILES, ''];
        }
        foreach ($uploads as ['name' => $name, 'tmp' => $tmp]) {
            $content = $this->uploaded($tmp);
            $why     = match (true) {
                is_null($content) => self::FILE_LOST,
                $content === ''   => self::FILE_EMPTY,
                default           => self::contentRefusal($name, $content),
            };
            if ($why !== '') {
                return [$why, $name];
            }
        }

        return null;
    }

    /** FILE_CONTENT when the editor would not upload $content as $name, or a data message would not carry it; '' = it may go */
    private static function contentRefusal(string $name, string $content): string
    {
        $detected = AttachmentPolicy::detect($content);
        if (UploadPolicy::refusal(UploadPolicy::USER, $name, $detected, strlen($content)) !== null) {
            return self::FILE_CONTENT;
        }
        try {
            AttachmentPolicy::file($name, $content, detected: $detected);
        } catch (\InvalidArgumentException) {
            return self::FILE_CONTENT;
        }

        return '';
    }

    /**
     * The uploads moved into the upload directory of the campaign slice, under a name no other file has
     * @param list<array{name: string, tmp: string}> $uploads
     * @return array<string, string> path => URL
     * @throws \RuntimeException none moved then
     */
    private function moveIn(Setup $setup, array $uploads): array
    {
        $moved = [];
        try {
            foreach ($uploads as ['name' => $name, 'tmp' => $tmp]) {
                $path = $this->move($setup->campaignSlice, $tmp, $name);
                // listed before its URL is asked: removed again when that fails
                $moved[$path] = '';
                $moved[$path] = $this->urlOf($setup->campaignSlice, $path);
            }
        } catch (\Throwable $e) {
            $this->discard(array_keys($moved));
            throw $e;
        }

        return $moved;
    }

    /**
     * The fields of the letter the form posts, as they are stored
     * @param array<array-key, mixed> $post
     * @return array{array<string, AA_Value>, bool}|string the fields, and whether the form picked an item gone
     *         since - its filter left out then; or the field of the form that cannot be taken
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
        $lost = false;
        if (array_key_exists('isds_membership', $post)) {
            $filter = $this->filter($setup, $post);
            if (in_array($filter, [self::RECIPIENTS, self::TOO_MANY], true)) {
                return $filter;
            }
            $lost = ($filter === self::FILTER_CHANGED);
            if (!$lost) {
                $fields[Schema::CAMPAIGN_FILTER] = new AA_Value($filter);
            }
        } elseif (array_key_exists('isds_pick', $post)) {
            // a pick changes the lists the form shows; without them it would change those stored, unseen
            return self::RECIPIENTS;
        }

        return [$fields, $lost];
    }

    /**
     * The recipient filter of the form, as the campaign stores it - with the item it picks (isds_pick)
     * @param array<array-key, mixed> $post
     * @return string the filter; RECIPIENTS = not one the page offers, TOO_MANY = too many ids,
     *                FILTER_CHANGED = the item picked is no active item of the source slice (any more)
     */
    private function filter(Setup $setup, array $post): string
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
        $pick   = $post['isds_pick'] ?? null;
        try {
            // one reader of a filter: the one sending reads it with
            $read = RecipientFilter::fromJson(json_encode($filter, JSON_THROW_ON_ERROR), $setup);

            return (is_null($pick) ? $read : $read->picked(is_string($pick) ? $pick : '', $setup, ($this->resolver)()))->toJson();
        } catch (\InvalidArgumentException|\JsonException) {
            return self::RECIPIENTS;
        } catch (\LengthException) {
            return self::TOO_MANY;
        } catch (\OutOfBoundsException) {
            // deleted or put in the trash since the page showed it: the letter is still saved
            return self::FILTER_CHANGED;
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

    /** @return array{code: string, campaign: string, made: bool} */
    private static function copied(string $code, string $campaign, bool $made = false): array
    {
        return ['code' => $code, 'campaign' => $campaign, 'made' => $made];
    }

    /** @return array{code: string, campaign: string, field: string, file: string, refiltered: bool} */
    private static function told(string $code, string $campaign, string $field = '', string $file = '', bool $refiltered = false): array
    {
        return ['code' => $code, 'campaign' => $campaign, 'field' => $field, 'file' => $file, 'refiltered' => $refiltered];
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

    /** the bytes of a file uploaded in this request; null when PHP did not receive it so, or it cannot be read */
    protected function uploaded(string $tmp): ?string
    {
        $content = is_uploaded_file($tmp) ? file_get_contents($tmp) : false;

        return is_string($content) ? $content : null;
    }

    /**
     * An upload moved into the upload directory of $slice - as $name, or with a number added when a file has it
     * @return string the path it got
     * @throws \RuntimeException not moved
     */
    protected function move(string $slice, string $tmp, string $name): string
    {
        $dir  = Files::destinationDir(AA_Slice::getModule($slice));
        $path = ($dir === false) ? false : Files::uploadFile(['name' => $name, 'tmp_name' => $tmp], $dir, '', 'new', $name);
        // its message names paths of the server
        return ($path === false) ? throw new \RuntimeException('An attachment could not be moved into the upload directory') : $path;
    }

    /** the URL of a file of the upload directory of $slice, as an upload field stores it */
    protected function urlOf(string $slice, string $path): string
    {
        return (AA_Slice::getModule($slice) ?? throw new \RuntimeException("No slice $slice"))->getUrlFromPath($path);
    }

    /** @param list<string> $paths files moved in for a save that did not happen */
    protected function discard(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
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
