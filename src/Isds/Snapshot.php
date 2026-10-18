<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA_Object;
use AA_Property;

/**
 * What a mass sending sends and to whom, frozen when it is queued; a sending reads them only from
 * here, so an item changed later sends nothing else. Kept in the database, as AA has no directory
 * its servers share that the web does not serve. object_text.value is text in the charset of the
 * install - cp1250 or utf8mb3 on some, which corrupt bytes and 4-byte characters: a part is base64,
 * CHUNK bytes a row, and the manifest, kept in the run's record, is ASCII JSON. It says what each part
 * must be, and every read() checks it. Nothing is deleted: it is the record of what went out.
 */
final class Snapshot extends AA_Object
{
    /** bytes of a row before base64 - 512 KiB after it */
    public const CHUNK = 384 * 1024;

    /** BatchFailed reason: a part is missing, or not what was frozen */
    public const CHANGED = 'CONTENT_CHANGED';

    /** a frozen recipient: its message item to be, the source item, the box, the names, why skipped */
    public const ROW = ['msg', 'src', 'box', 'name', 'official', 'reason'];

    /** bytes of a part read() takes: no file is larger, and the size in a manifest is bounded */
    private const MAX_PART = Message::MAX_BYTES;

    /** @var list<string> the recipients as JSON, in base64 rows */
    protected $rcpt = [];
    /** @var list<string> the files in base64 rows, each file from a row of its own */
    protected $files = [];

    private string $manifest = '';
    /** @var list<array<string, string>> */
    private array $recipients = [];
    private ?Letter $letter = null;

    public static function getClassProperties(): array
    {
        return [
            'rcpt'  => new AA_Property('rcpt', 'rcpt', 'text', true, true, 'text'),
            'files' => new AA_Property('files', 'files', 'text', true, true, 'text'),
        ];
    }

    /**
     * A new snapshot, to be saved in the transaction that starts the run
     * @param array<Recipient>                $recipients as Recipients::resolve() gave them, skipped ones too
     * @param array{sha256: string, time: int} $register   of the register they were checked against (RegisterIndex::info())
     * @param string                           $campaign   the long id of the campaign item, the owner - in small letters, as BatchRun::start() takes the run's subject
     * @throws \InvalidArgumentException the recipients take more than read() takes, or $campaign is no long id
     */
    public static function of(Letter $letter, array $recipients, array $register, string $campaign): self
    {
        // read() compares the owner with the run's subject: another spelling would fail every step
        if (!preg_match('/^[0-9a-f]{32}\z/', $campaign)) {
            throw new \InvalidArgumentException('The owner of a snapshot is the long id of a campaign');
        }
        $rows = array_map(static fn(Recipient $r): array => [new_id(), $r->itemId, $r->box, $r->name, $r->official, $r->reason], array_values($recipients));
        // names are raw item text: a broken one must not stop the sending
        $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > self::MAX_PART) {
            throw new \InvalidArgumentException('The list of recipients is too long to freeze');
        }
        $snapshot = new self();
        $snapshot->setNew(new_id(), $campaign);
        $snapshot->rcpt = self::chunks($json);
        $files          = [];
        foreach ($letter->files as $file) {
            array_push($snapshot->files, ...self::chunks($file->content));
            $files[] = ['name' => $file->name, 'mime' => $file->mimeType, 'meta' => $file->metaType] + self::stamp($file->content);
        }
        $snapshot->manifest = json_encode([
            'annotation' => $letter->annotation,
            'to_hands'   => $letter->toHands,
            'files'      => $files,
            'rcpt'       => ['n' => count($rows)] + self::stamp($json),
            'register'   => ['sha256' => (string) $register['sha256'], 'time' => (int) $register['time']],
        ], JSON_THROW_ON_ERROR);
        return $snapshot;
    }

    /**
     * Writes it in the caller's transaction, so that a rollback leaves no snapshot behind - whole or not
     * at all only where object_text is InnoDB, which the caller checks (BatchRun::requireTransactional())
     */
    public function save(): false|string
    {
        if ($this->rcpt === []) {
            // a snapshot read() holds no rows: saving it would wipe the record of what went out
            throw new \LogicException('Only a snapshot made by of() can be saved');
        }
        return DB_AA::transaction(fn(): false|string => parent::save());
    }

    /** ASCII JSON for the run's record: the subject, to hands, names and types of the files, sizes and
     *  hashes - no body, attachment or recipient, no secret */
    public function manifest(): string
    {
        return $this->manifest;
    }

    /**
     * The snapshot $id of the campaign $owner, checked against $manifest. Not AA_Object::load(): it
     * caches per process.
     * @param bool $files the letter and the attachments too - only sending needs them
     * @throws BatchFailed CHANGED
     */
    public static function read(string $id, string $manifest, bool $files, string $owner): self
    {
        $m = json_decode($manifest, true);
        if (!is_array($m) || !is_array($m['rcpt'] ?? null) || !is_array($m['files'] ?? null)) {
            throw self::changed('manifest');
        }
        $parts = $files ? ['rcpt', 'files'] : ['rcpt'];
        $rows  = array_fill_keys([...$parts, 'aa_owner'], []);
        foreach (DB_AA::select([], 'SELECT property, priority, value FROM `object_text`', [['object_id', $id], ['property', [...$parts, 'aa_owner']]]) as $row) {
            $priority = (int) $row['priority'];
            // which of two rows of one place counted would depend on the order the database returns them in
            if (isset($rows[$row['property']][$priority])) {
                throw self::changed('rows');
            }
            $rows[$row['property']][$priority] = (string) $row['value'];
        }
        // a record pointing at the snapshot of another campaign sends nothing of it
        if (array_values($rows['aa_owner']) !== [$owner]) {
            throw self::changed('owner');
        }
        unset($rows['aa_owner']);
        $snapshot = new self();
        $snapshot->setNew($id, '');
        $snapshot->manifest = $manifest;
        try {
            $at   = 0;
            $list = json_decode(self::part($rows['rcpt'], $at, $m['rcpt'], 'rcpt'), true);
            if (!is_array($list) || !array_is_list($list) || (count($list) !== ($m['rcpt']['n'] ?? null))) {
                throw self::changed('rcpt');
            }
            $snapshot->recipients = array_map(self::row(...), $list);
            if ($files) {
                $at   = 0;
                $made = [];
                foreach ($m['files'] as $f) {
                    $made[] = new File(self::text($f, 'name'), self::text($f, 'mime'), self::text($f, 'meta'), self::part($rows['files'], $at, $f, 'files'));
                }
                $snapshot->letter = new Letter(self::text($m, 'annotation'), $made, self::text($m, 'to_hands'));
            }
        } catch (\InvalidArgumentException|\TypeError|\ValueError) {
            // a manifest or a list of another shape than of() writes
            throw self::changed('manifest');
        }
        // a row no part took
        if (array_filter($rows)) {
            throw self::changed('rows');
        }
        return $snapshot;
    }

    /**
     * Bytes of the files $manifest lists, known before they are read - at most those of one message,
     * as of() writes them: memory_limit is raised by what this says, and a manifest edited to list more
     * would raise it without bound
     * @throws BatchFailed CHANGED for a manifest of another shape
     */
    public static function filesSize(string $manifest): int
    {
        $m     = json_decode($manifest, true);
        $files = is_array($m) ? ($m['files'] ?? null) : null;
        if (!is_array($files) || (count($files) > Message::MAX_FILES)) {
            throw self::changed('manifest');
        }
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += self::sizeOf($file, 'manifest');
            if ($bytes > Message::MAX_BYTES) {
                throw self::changed('manifest');
            }
        }
        return $bytes;
    }

    /** a snapshot of $id is stored */
    public static function exists(string $id): bool
    {
        return DB_AA::test('object_text', [['object_id', $id], ['property', 'aa_type'], ['value', self::class]]);
    }

    /** @return list<array<string, string>> rows of ROW keys, in the order frozen */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /** @throws \LogicException read without the files */
    public function letter(): Letter
    {
        return $this->letter ?? throw new \LogicException('The snapshot was read without the files');
    }

    /** @return list<string> base64 rows - one at a time, not beside a split copy of $bytes */
    private static function chunks(string $bytes): array
    {
        $rows = [];
        for ($at = 0; $at < strlen($bytes); $at += self::CHUNK) {
            $rows[] = base64_encode(substr($bytes, $at, self::CHUNK));
        }
        return $rows;
    }

    /**
     * @param array<array-key, mixed> $values
     * @throws \InvalidArgumentException missing or no text - '' would pass for "to hands" and drop it from every letter
     */
    private static function text(array $values, string $key): string
    {
        return is_string($values[$key] ?? null) ? $values[$key] : throw new \InvalidArgumentException("No text $key");
    }

    /**
     * A frozen recipient by ROW keys - checked, as array_map() coerces numbers and booleans to text
     * @param array<array-key, mixed> $row
     * @return array<string, string>
     * @throws \InvalidArgumentException|\ValueError not a row of() wrote
     */
    private static function row(array $row): array
    {
        $texts = array_map(static fn(mixed $v): string => is_string($v) ? $v : throw new \InvalidArgumentException('No text in a row'), $row);
        return array_is_list($row) ? array_combine(self::ROW, $texts) : throw new \InvalidArgumentException('A row is no list');
    }

    /** @return array{size: int, xxh128: string} */
    private static function stamp(string $bytes): array
    {
        return ['size' => strlen($bytes), 'xxh128' => hash('xxh128', $bytes)];
    }

    /**
     * The bytes of the part whose rows start at $at, checked against its stamp; its rows leave $rows
     * as they are decoded, so that the base64 does not stay in memory beside them
     * @param array<int, string>   $rows  by priority
     * @param array<string, mixed> $stamp
     * @throws BatchFailed
     */
    private static function part(array &$rows, int &$at, array $stamp, string $what): string
    {
        $size  = self::sizeOf($stamp, $what);
        $bytes = '';
        for ($end = $at + intdiv($size + self::CHUNK - 1, self::CHUNK); $at < $end; ++$at) {
            $chunk = base64_decode($rows[$at] ?? throw self::changed($what), true);
            if ($chunk === false) {
                throw self::changed($what);
            }
            unset($rows[$at]);
            $bytes .= $chunk;
        }
        if ((strlen($bytes) !== $size) || (hash('xxh128', $bytes) !== ($stamp['xxh128'] ?? null))) {
            throw self::changed($what);
        }
        return $bytes;
    }

    /**
     * The size of a stamp of the manifest, as of() writes it
     * @throws BatchFailed CHANGED
     */
    private static function sizeOf(mixed $stamp, string $what): int
    {
        $size = is_array($stamp) ? ($stamp['size'] ?? null) : null;
        return (is_int($size) && ($size >= 0) && ($size <= self::MAX_PART)) ? $size : throw self::changed($what);
    }

    private static function changed(string $what): BatchFailed
    {
        return new BatchFailed(self::CHANGED, 'snapshot', $what);
    }
}
