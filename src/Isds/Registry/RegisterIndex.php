<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\IO\DB\DB_AA;
use AA\Util\SafeFile;

/**
 * The boxes of the downloaded register (Sync::registerFile()) in a file next to it, a line each - id,
 * IČO, name - so a page asking for some boxes need not parse the 17 MB of XML (half a second).
 *
 * Both files lie in the upload directory, which the web server serves and other PHP users of the
 * server may write into. Only the hashes RegisterRecord keeps in the install's database make them the
 * register this AA downloaded and the index it made of it: another register is not indexed until it
 * is downloaded again, another index is made again.
 *
 * Names are raw text of an outside file - escape them (safe()) when shown.
 */
class RegisterIndex
{
    public const FILE = 'isds-seznam-ds-ovm.idx';

    /** state(): the register and its index are ready */
    public const OK = 'ok';

    /** state(): ready, but the register was downloaded more than Sync::OLD_DAYS ago */
    public const OLD = 'old';

    /** state(): no register has been downloaded */
    public const MISSING = 'missing';

    /** state(): the register or its index cannot be used in this request - problem() says why */
    public const UNREADABLE = 'unreadable';

    /** info(): the register this AA downloaded, as far as a look at the file tells */
    public const RECORDED = 'recorded';

    /** info(): a register AA has no record of downloading - an older AA downloaded it, or it was put
     *  there by hand */
    public const UNRECORDED = 'unrecorded';

    /** info(): the register this AA downloaded is gone, or another file lies in its place - which only
     *  reading it tells (Sync::registerBoxes()) */
    public const REPLACED = 'replaced';

    /** problem(): the register is not the one the record vouches for */
    public const CHANGED = 'The register of data boxes is not the one AA downloaded - download it again';

    /** the IČO of a box whose IČO in the register is no IČO - it equals no item's */
    public const BAD_ICO = '?';

    /**
     * RegisterRecord, '' = unset: sha256, size and time of the downloaded register; sha256 of its
     * index; time, register sha256 and message of the last failed attempt to make the index
     */
    public const RECORD = ['xml' => '', 'size' => '', 'time' => '', 'index' => '', 'failed' => '', 'failed_of' => '', 'failure' => ''];

    /** the format; the register's sha256 follows */
    private const HEADER = 'aa-isds-register-index-1';

    private const LOCK = 'isds-register-index';

    /** seconds a request waits for another one making the index, which takes about half a second */
    private const LOCK_WAIT = 3;

    /** seconds a failed attempt holds the next ones back - each parses 17 MB */
    private const RETRY = 600;

    /** bytes of a name in the index - an official name is a line of text; a longer one is cut */
    private const MAX_NAME = 1000;

    /** bytes of a line of the index: id, IČO, MAX_NAME and the separators fit; a longer one is damage */
    private const MAX_LINE = 1024;

    /** bytes of the index - some 1 MB per 17 MB of register; scan() reads no more, as a file someone
     *  keeps writing to would never end, so make() writes no more either */
    private const MAX_INDEX = 8 * 1024 * 1024;

    /** @var array<string, string> index path and register sha256 => why this process could not make it */
    private static array $failed = [];

    /** null = the install has no upload directory */
    private readonly ?string $register;

    private readonly string $index;

    private ?string $state = null;

    private string $problem = '';

    /** @var array<string, string> RECORD as last read */
    private array $record = self::RECORD;

    /** the index as read once its hash matched the record's - the bytes this object answers from */
    private string $checkedText = '';

    /** the record's index sha256 $checkedText matched; '' = nothing checked yet */
    private string $checkedSha = '';

    /** @param ?string $register the register; Sync::registerFile() when null */
    public function __construct(?string $register = null)
    {
        try {
            $register ??= Sync::registerFile();
        } catch (\RuntimeException $e) {
            $this->problem = $e->getMessage();
        }
        $this->register = $register;
        $this->index    = ($register === null) ? '' : dirname($register) . '/' . self::FILE;
    }

    /**
     * OK, OLD, MISSING or UNREADABLE; first makes the index when there is none of the recorded register.
     * Looks once per object.
     */
    public function state(): string
    {
        return $this->state ??= $this->look();
    }

    /**
     * The state() is OK or OLD: recipients can be checked against the register; else nobody can tell
     * them. A lookup() that finds the index replaced and cannot make it again turns it false.
     */
    public function usable(): bool
    {
        return in_array($this->state(), [self::OK, self::OLD], true);
    }

    /**
     * Why a sending cannot use the register now, the index checked whole; '' = it can, or there is none
     * (MISSING). Raw, it may name a path - escape it (safe()).
     */
    public function problem(): string
    {
        try {
            if ($this->usable()) {
                $this->lookup([]);
            }
        } catch (\RuntimeException) {
            // lookup() has set the problem
        }
        return $this->problem;
    }

    /**
     * The boxes of $boxes the register has, in one pass over the index - ask for all of them at once.
     * @param list<string> $boxes ids as ISDS knows them (Box::normalizeId())
     * @return array<array-key, array{id: string, ico: string, name: string}> by box id - PHP makes an id
     *         of digits only an int key, so take the id from the row; ico as Box::normalizeIco() gives
     *         it, '' = none, BAD_ICO = no IČO in the register
     * @throws UnusableRegisterException the state() is neither OK nor OLD, or the index is not the
     *                                    recorded one and cannot be made again
     */
    public function lookup(array $boxes): array
    {
        if (!$this->usable()) {
            throw new UnusableRegisterException("The register of data boxes is $this->state: $this->problem");
        }
        $wanted = array_fill_keys($boxes, true);
        // replaced since state() made it: made again, unless the register was replaced too
        $found = $this->scan($wanted) ?? ($this->rebuild() ? $this->scan($wanted) : null);
        if ($found === null) {
            $this->state     = self::UNREADABLE;
            $this->problem   = $this->problem ?: "The index of the register of data boxes is not the one AA made: $this->index";
            throw new UnusableRegisterException($this->problem);
        }
        return $found;
    }

    /**
     * The register as the record vouches for it, from a look at the file and a read of the record - not
     * of the register's bytes, which Sync::registerBoxes() checks. null when nothing was downloaded.
     * @return ?array{state: string, time: int, size: int, sha256: string} state RECORDED, UNRECORDED or
     *         REPLACED; time, size and sha256 of the recorded download, 0 and '' when UNRECORDED
     * @throws \RuntimeException no upload directory, or one PHP cannot look into - the message names it
     */
    public function info(): ?array
    {
        if ($this->register === null) {
            throw new \RuntimeException($this->problem);
        }
        $exists       = Sync::registerExists($this->register);
        $this->record = $this->recorded();
        $recorded     = ($this->record['xml'] !== '');
        if (!$exists && !$recorded) {
            return null;
        }
        return [
            'state'  => $recorded ? ($exists ? self::RECORDED : self::REPLACED) : self::UNRECORDED,
            'time'   => (int) $this->record['time'],
            'size'   => (int) $this->record['size'],
            'sha256' => $this->record['xml'],
        ];
    }

    /** @return array<string, string> RECORD as stored now, past what state() read */
    public function recorded(): array
    {
        return $this->readRecord() + self::RECORD;
    }

    /**
     * Records the register Sync::download() has just put in place and makes its index. A failure to
     * make the index is logged and left to the first page that needs it.
     * @throws UnrecordedException the record cannot be written
     */
    public function downloaded(string $sha256, int $size): void
    {
        try {
            $this->writeRecord(['xml' => $sha256, 'size' => $size, 'time' => time(), 'index' => '', 'failed' => '', 'failed_of' => '', 'failure' => '']);
        } catch (\RuntimeException $e) {
            throw new UnrecordedException('The register of data boxes is downloaded, but its record cannot be written: ' . $e->getMessage(), 0, $e);
        }
        try {
            // without the lock another request is making it
            if ($this->lock()) {
                try {
                    $this->record = $this->recorded();
                    $error        = $this->make();
                    if ($error !== '') {
                        $this->failed($error);
                    }
                } finally {
                    $this->unlock();
                }
            }
        } catch (\Throwable $e) {
            $this->log($e->getMessage());
        }
    }

    private function look(): string
    {
        try {
            $info = $this->info();
        } catch (\RuntimeException $e) {
            // no upload directory, or one PHP cannot look into - GlusterFS refuses it at times
            $this->problem = $e->getMessage();
            return self::UNREADABLE;
        }
        if ($info === null) {
            return self::MISSING;
        }
        if ($info['state'] !== self::RECORDED) {
            $this->problem = self::CHANGED;
            return self::UNREADABLE;
        }
        if (($this->scan([]) === null) && !$this->rebuild()) {
            return self::UNREADABLE;
        }
        return Sync::isOld($info['time'], time()) ? self::OLD : self::OK;
    }

    /** One request at a time parses the register; the others wait for it, or give up for this request. */
    private function rebuild(): bool
    {
        $key    = $this->index . '|' . $this->record['xml'];
        $recent = ($this->record['failed_of'] === $this->record['xml']) && ((time() - (int) $this->record['failed']) < self::RETRY);
        if (isset(self::$failed[$key]) || $recent) {
            $this->problem = self::$failed[$key] ?? $this->record['failure'];
            return false;
        }
        if (!$this->lock()) {
            $this->problem = self::$failed[$key] = 'Another request is making the index of the register of data boxes';
            return false;
        }
        try {
            // the request this one waited for may have made it, or a download replaced the register
            $this->record = $this->recorded();
            if ($this->scan([]) !== null) {
                return true;
            }
            $error = $this->make();
            return ($error === '') || $this->failed($error);
        } finally {
            $this->unlock();
        }
    }

    /**
     * The boxes of $wanted in the index; the index is read and hashed once per object, and only the
     * bytes that matched the record are parsed.
     * @param array<array-key, true> $wanted
     * @return ?array<array-key, array{id: string, ico: string, name: string}> null: not the index the
     *                                                                         record vouches for
     */
    private function scan(array $wanted): ?array
    {
        $sha    = $this->record['index'];
        $header = self::header($this->record['xml']);
        if (($sha === '') || (($this->checkedSha !== $sha) && !$this->check($sha))) {
            return null;
        }
        $text = $this->checkedText;
        if (!str_starts_with($text, $header)) {
            return null;
        }
        if ($wanted === []) {
            return [];
        }
        $found = [];
        $end   = strlen($text);
        for ($at = strlen($header); $at < $end; $at = $next + 1) {
            // check() refuses a text whose last line has no end, so this never triggers
            $next = strpos($text, "\n", $at);
            if ($next === false) {
                break;
            }
            $parts = explode("\t", substr($text, $at, $next - $at), 3);
            if (isset($wanted[$parts[0]], $parts[2])) {
                $found[$parts[0]] ??= ['id' => $parts[0], 'ico' => $parts[1], 'name' => $parts[2]];
            }
        }
        return $found;
    }

    /** Reads the index and keeps it when it is the one the record's $sha vouches for, undamaged. */
    private function check(string $sha): bool
    {
        try {
            $text = SafeFile::read($this->index, $this->maxIndex());
        } catch (\RuntimeException) {
            return false;
        }
        // a line without its end, or longer than any make() writes, is damage
        if (!hash_equals($sha, hash('sha256', $text)) || !str_ends_with($text, "\n") || (preg_match('/(?:\A|\n)[^\n]{' . self::MAX_LINE . '}/', $text) !== 0)) {
            return false;
        }
        $this->checkedSha  = $sha;
        $this->checkedText = $text;
        return true;
    }

    /**
     * Writes the index of the recorded register and records its hash; the caller holds the lock.
     * @return string why it failed, '' = made
     */
    private function make(): string
    {
        $text = '';
        try {
            SafeFile::replace($this->index, function () use (&$text): string {
                return $text = $this->text();
            });
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $sha = hash('sha256', $text);
        try {
            $this->writeRecord(['index' => $sha]);
        } catch (\RuntimeException $e) {
            // nothing vouches for an index without its hash in the record
            return 'The index of the register of data boxes is made, but its hash cannot be recorded: ' . $e->getMessage();
        }
        $this->record['index'] = $sha;
        return '';
    }

    /**
     * The index of the recorded register, parsed from the very bytes that were hashed
     * @throws \RuntimeException
     */
    private function text(): string
    {
        $boxes = $this->boxes((string) $this->register, $this->record['xml']) ?? throw new \RuntimeException(self::CHANGED);
        $text  = self::header($this->record['xml']);
        foreach ($boxes as $box) {
            $ico   = ($box->ico === '') ? '' : (Box::normalizeIco($box->ico) ?: self::BAD_ICO);
            $name  = mb_strcut((string) preg_replace('/[\t\r\n]+/', ' ', $box->name), 0, self::MAX_NAME, 'UTF-8');
            $text .= $box->id . "\t" . $ico . "\t" . $name . "\n";
        }
        if (strlen($text) > $this->maxIndex()) {
            throw new \RuntimeException(sprintf('The register of data boxes is unusually large: its index would have %d bytes, AA reads at most %d', strlen($text), $this->maxIndex()));
        }
        return $text;
    }

    /**
     * Remembers why the index could not be made - in this process, and in the record for RETRY
     * seconds, so pages that poll do not each parse 17 MB - and logs it; the caller holds the lock.
     */
    private function failed(string $error): bool
    {
        self::$failed[$this->index . '|' . $this->record['xml']] = $error;
        $this->problem = $error;
        try {
            $this->writeRecord(['failed' => time(), 'failed_of' => $this->record['xml'], 'failure' => mb_strcut($error, 0, 500)]);
        } catch (\RuntimeException $e) {
            // this process holds back all the same; other requests try again
            $error .= ' - not recorded: ' . $e->getMessage();
        }
        $this->log($error);
        return false;
    }

    private static function header(string $sha256): string
    {
        return self::HEADER . " $sha256\n";
    }

    // ------------------------------------------------------------ what tests replace

    /** @return array<string, string> RegisterRecord::read() */
    protected function readRecord(): array
    {
        return RegisterRecord::read();
    }

    /** @param array<string, string|int> $values */
    protected function writeRecord(array $values): void
    {
        RegisterRecord::write($values);
    }

    protected function log(string $message): void
    {
        \AA_Log::write(Sync::LOG_EVENT, 'index', $message);
    }

    protected function maxIndex(): int
    {
        return self::MAX_INDEX;
    }

    /** @return bool got it - unlock() then; false also when the database gives no lock */
    protected function lock(): bool
    {
        return DB_AA::lock(self::LOCK, self::LOCK_WAIT);
    }

    protected function unlock(): void
    {
        DB_AA::unlock(self::LOCK);
    }

    /**
     * @return ?iterable<Box> SdsList::fromVerifiedFile()
     * @throws \RuntimeException
     */
    protected function boxes(string $register, string $sha256): ?iterable
    {
        return SdsList::fromVerifiedFile($register, $sha256);
    }
}
