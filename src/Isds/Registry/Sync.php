<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\Http\ClientInterface;
use AA\IO\DB\DB_AA;
use AA_Set;
use AA_Value;
use Files;
use ItemContent;
use zids;

/**
 * Pairs the items of one slice with the register of data boxes and writes the boxes a person ticked
 * into the items' data box field - that field only. The page and the State Check both read the rows
 * through report().
 *
 * It reads the items past the slice's reading password. Call it only from the slice's admin page,
 * after checking PS_EDIT_ALL_ITEMS (write() changes items for whoever runs it), and from the State
 * Check - never from a template command, a view, the API or any other public entry. State Check
 * findings and their mail give counts, never item names.
 * Item values and messages go out raw (see MatchReport) - escape them (safe()).
 */
class Sync
{
    /** one copy of the register per install, in its upload directory - shared by the servers, unlike
     *  the system temp directory; the register is public open data */
    public const REGISTER_FILE = 'isds-seznam-ds-ovm.xml';

    /** rows one write() takes - each is a storeItem() of its own; the rest waits for the next submit */
    public const MAX_WRITE = 500;

    /** days; an older register is worth downloading again - authorities get new boxes and merge */
    public const OLD_DAYS = 7;

    /** seconds since the last download or attempt before another one - each is 17 MB from the operator,
     *  a failing one too */
    public const MIN_DOWNLOAD_AGE = 600;

    /** download() fetched the register */
    public const DOWNLOADED = 'downloaded';

    /** download() found another download running */
    public const RUNNING = 'running';

    /** download() found the last download or attempt younger than MIN_DOWNLOAD_AGE */
    public const RECENT = 'recent';

    /** AA log type of the download attempts and of what failed with the register */
    public const LOG_EVENT = 'ISDS_REG';

    /** DB_AA::lock() of the downloads - one at a time for all servers of the database */
    private const DOWNLOAD_LOCK = 'isds-register-download';

    /** items GetItemContent() reads at once */
    private const CHUNK = 500;

    /** storedHash() */
    private const HASH = '/^[0-9a-f]{12}\z/';

    /**
     * As "Modify items" with "Silent" ticked: only the field is written - no events (an ITEM_UPDATED
     * mail per item), no feeding, no last_edit, no computed fields; the history and the item log
     * still record the change and who made it, unless the slice keeps no history. The page cache is
     * invalidated once, after all rows. Without computed fields no template of the slice (com, co2,
     * seo) runs while whileReadable() holds it open, so nothing it reads stays in the caches.
     */
    private const STORE_MODE = 'update_silent';

    /** @var array<string, true> the items the last items() read - write() changes no other */
    private array $loaded = [];

    /** @param Config $config one errors() accepts for the slice's fields as they are now */
    public function __construct(private readonly Config $config) {}

    /**
     * Where the register is, in the directory of Files::aadestinationDir() - without creating it: only
     * download() does, a page or a check that only looks leaves no directory behind.
     * @throws \RuntimeException the install has no upload directory (IMG_UPLOAD_PATH)
     */
    public static function registerFile(): string
    {
        $base = defined('IMG_UPLOAD_PATH') ? (string) constant('IMG_UPLOAD_PATH') : '';
        if ($base === '') {
            throw new \RuntimeException('IMG_UPLOAD_PATH is not set in config.php, so there is no directory for the register of data boxes');
        }
        return $base . constant('AA_ID') . '/' . self::REGISTER_FILE;
    }

    /**
     * Whether a file lies at the register's path - the only look at it. Not at its time or size: any PHP
     * user of the server may set them, so registerInfo() takes the recorded ones.
     * @param ?string $file registerFile() when null
     * @throws \RuntimeException no upload directory, or one PHP cannot look into - the message names it
     */
    public static function registerExists(?string $file = null): bool
    {
        $file ??= self::registerFile();
        clearstatcache();
        if (@stat($file) !== false) {
            return true;
        }
        // a missing file is no register yet - unless a directory on the way, which PHP may not search,
        // hides it: then the nearest one PHP sees is that directory
        $dir = self::nearestDir(dirname($file));
        if (!self::searchable($dir)) {
            throw new \RuntimeException("PHP cannot look into the directory $dir on the way to the register of data boxes");
        }
        return false;
    }

    /**
     * The downloaded register as its record vouches for it - see RegisterIndex::info()
     * @param ?string $file registerFile() when null
     * @return ?array{state: string, time: int, size: int, sha256: string}
     * @throws \RuntimeException see RegisterIndex::info()
     */
    public static function registerInfo(?string $file = null): ?array
    {
        return static::index($file ?? self::registerFile())->info();
    }

    /** a register downloaded at $downloaded (registerInfo()) is older than OLD_DAYS */
    public static function isOld(int $downloaded, int $now): bool
    {
        return ($now - $downloaded) > self::OLD_DAYS * 86400;
    }

    /**
     * What a page sends with its form and compares on the submit: the sha256 of the register - a
     * download of another one changes it - and the settings, which decide the rows. The submit is
     * refused when either changed since the person looked at the rows.
     * @param string $sha256 registerInfo()'s
     */
    public static function registerStamp(string $sha256, Config $config): string
    {
        return $sha256 . '-' . md5(serialize($config->values()));
    }

    /**
     * Downloads the register into registerFile(), creating its directory, and records it for its
     * RegisterIndex, unless another download is running or the last recorded download or attempt is
     * younger than MIN_DOWNLOAD_AGE. A failed download keeps the previous file and still counts as an
     * attempt.
     * @param ?string $file registerFile() when null; another file only in an existing directory, which
     *                      is not created
     * @return array{status: string, last: int} status DOWNLOADED, RUNNING or RECENT; last: for RECENT
     *                                          the time of the last download or attempt, else 0
     * @throws \RuntimeException the directory cannot be created or written to, or the database gives no
     *                           lock - nothing is fetched; see also SdsList::download()
     * @throws UnrecordedException the register is fetched and in place, but not recorded
     */
    public static function download(?ClientInterface $http = null, ?int $now = null, ?string $file = null): array
    {
        if ($file === null) {
            $file  = self::registerFile();
            $ready = (@Files::aadestinationDir() !== false);
        } else {
            $ready = @is_dir(dirname($file));
        }
        $dir = dirname($file);
        clearstatcache();
        if (!$ready || !@is_writable($dir) || !self::searchable($dir)) {
            throw new \RuntimeException(self::unusableDir($dir));
        }
        if (!static::lockDownload($holder)) {
            // no holder: no lock at all (no connection) - "running" would hold every download back
            if ($holder === null) {
                throw new \RuntimeException('The database gave no lock for the download of the register of data boxes');
            }
            return ['status' => self::RUNNING, 'last' => 0];
        }
        try {
            $now ??= time();
            $index = static::index($file);
            // the recorded time, not the file's, which any PHP user of the server may set; a time ahead
            // of now (the clock of another server) would block for as long
            $times = [static::lastAttempt($now), (int) $index->recorded()['time']];
            $last  = max([0, ...array_filter($times, static fn(int $t): bool => $t <= $now)]);
            if (($last > 0) && (($now - $last) < self::MIN_DOWNLOAD_AGE)) {
                return ['status' => self::RECENT, 'last' => $last];
            }
            static::recordAttempt();
            $written = SdsList::download($http ?? SdsList::client(), $file);
            $index->downloaded($written['sha256'], $written['size']);
            return ['status' => self::DOWNLOADED, 'last' => 0];
        } finally {
            static::unlockDownload();
        }
    }

    /** why PHP cannot download into $dir, naming the directory that is the cause */
    private static function unusableDir(string $dir): string
    {
        $seen = self::nearestDir($dir);
        return match (true) {
            !self::searchable($seen) => "PHP cannot look into the directory $seen on the way to the register of data boxes",
            $seen !== $dir           => "PHP cannot create the directory $dir for the register of data boxes in $seen",
            default                  => "PHP cannot write into the directory of the register of data boxes $dir",
        };
    }

    /** $path, or the nearest directory above it PHP sees - an open_basedir without the upload path
     *  must not warn at every step */
    private static function nearestDir(string $path): string
    {
        while (!@file_exists($path) && (dirname($path) !== $path)) {
            $path = dirname($path);
        }
        return $path;
    }

    private static function searchable(string $dir): bool
    {
        return @is_dir($dir) && @is_executable($dir);
    }

    /**
     * The boxes of the downloaded register when it is the one with $sha256 (registerInfo()), all read:
     * damage anywhere in the file throws here, not in the middle of a report, where it would pass for a
     * fault of the settings. The 17 MB of XML are some 8 MB of boxes.
     * @param ?string $file registerFile() when null
     * @return ?list<Box> null: another file lies in its place - download it again
     * @throws \RuntimeException see SdsList::fromVerifiedFile()
     */
    public static function registerBoxes(string $sha256, ?string $file = null): ?array
    {
        $boxes = SdsList::fromVerifiedFile($file ?? self::registerFile(), $sha256);
        return ($boxes === null) ? null : iterator_to_array($boxes, false);
    }

    /**
     * The register's box for every item of the slice to pair, items ordered by name.
     * @param iterable<Box> $boxes registerBoxes(), read once
     * @throws \RuntimeException see checkSettings()
     */
    public function report(iterable $boxes): MatchReport
    {
        return (new Matcher($this->config->districtTypes()))->match($boxes, $this->items());
    }

    /**
     * The items to pair as Matcher::match() takes them: the active items of the slice (pending and
     * expired ones too where the slice shows them), of the configured types when there are any.
     * @return array<string, array{name: string, ico: string, ds: string, type: string}> item id => values
     * @throws \RuntimeException see checkSettings()
     */
    public function items(): array
    {
        $this->checkSettings();
        $items = [];
        foreach (array_chunk($this->itemIds(), self::CHUNK) as $chunk) {
            // GetItemContent() returns the items in id order, the report keeps the slice's
            $content = $this->loadContent($chunk);
            $ordered = [];
            foreach ($chunk as $id) {
                if (isset($content[$id])) {
                    $ordered[$id] = $content[$id];
                }
            }
            $items += $this->itemsFrom($ordered);
        }
        $this->loaded = array_fill_keys(array_keys($items), true);
        return $items;
    }

    /**
     * The values of the items in $content - the first value of each field, '' when there is none; the
     * type trimmed, as Config keeps the types it is compared with. Items of other types than
     * Config::types() are left out.
     * @param array<array-key, mixed> $content item id => content as GetItemContent() returns it
     * @return array<string, array{name: string, ico: string, ds: string, type: string}>
     */
    public function itemsFrom(array $content): array
    {
        $fields = $this->fields();
        $types  = array_fill_keys($this->config->types(), true);
        $items  = [];
        foreach ($content as $item_id => $content4id) {
            $item   = new ItemContent(is_array($content4id) ? $content4id : []);
            $values = [];
            foreach ($fields as $key => $field_id) {
                // a number-stored field may come as an int; no field or no value (false) gives ''
                $values[$key] = (string) $item->getValue($field_id);
            }
            $values['type'] = trim($values['type']);
            if ($types && !isset($types[$values['type']])) {
                continue;
            }
            $items[(string) $item_id] = $values;
        }
        return $items;
    }

    /**
     * Item ids of the rows a page ticks before a person looks: a new or changed box found by IČO,
     * the item's IČO the box's own, and the box no other item holds or would get. A name match is
     * only ever ticked by hand.
     * @return list<string>
     */
    public static function defaultTicked(MatchReport $report): array
    {
        $shared = $report->sharedBoxes();
        $ticked = [];
        foreach ($report->rows as $row) {
            if ($row->writable() && ($row->method === MatchRow::BY_ICO) && !$row->icoMismatch && !isset($shared[$row->newDs])) {
                $ticked[] = (string) $row->itemId;
            }
        }
        return $ticked;
    }

    /**
     * The value of a row's checkbox: the box offered and a hash of the box the item had, as the person
     * saw them - one value, so the form adds no input per row (max_input_vars). A hash, as a browser
     * posts a line break of the item's text as CR LF, and the item's text stays out of the form.
     */
    public static function tickValue(MatchRow $row): string
    {
        return $row->newDs . '|' . self::storedHash($row->oldDs);
    }

    /**
     * The rows to write for the boxes a person ticked: those whose row in this request's report()
     * still offers the very box the person saw, to an item that still has the box the person saw -
     * another editor may have changed the item, or the register its box, since. The box written is the
     * register's (the row's); the posted one only has to equal it. At most $cap rows, in report order.
     * @param array<array-key, mixed> $ticked item id => tickValue() of its row, as a form posts it
     * @return array{rows: list<MatchRow>, stale: list<string>, left: int} stale: the ticked items whose
     *         row offers another box or none now, whose item has another box now, or which are gone -
     *         a page says they changed meanwhile; left: ticked rows beyond $cap, for the next submit. A
     *         ticked item in none of them has the box already, or is no tick: its posted value is no
     *         text, or not a box id of Box::ID and a hash as tickValue() makes it.
     */
    public static function toWrite(MatchReport $report, array $ticked, int $cap = self::MAX_WRITE): array
    {
        $seen = [];
        foreach ($ticked as $id => $value) {
            $parts = is_string($value) ? explode('|', $value, 2) : [];
            if ((count($parts) === 2) && (preg_match(Box::ID, $parts[0]) === 1) && (preg_match(self::HASH, $parts[1]) === 1)) {
                $seen[(string) $id] = $parts;
            }
        }
        $rows  = [];
        $stale = [];
        $left  = 0;
        foreach ($report->rows as $row) {
            $id = (string) $row->itemId;
            if (!isset($seen[$id])) {
                continue;
            }
            if (($row->newDs !== $seen[$id][0]) || (self::storedHash($row->oldDs) !== $seen[$id][1])) {
                $stale[] = $id;
            } elseif ($row->writable()) {
                if (count($rows) < $cap) {
                    $rows[] = $row;
                } else {
                    ++$left;
                }
            }
            unset($seen[$id]);
        }
        // items no longer paired: deleted, of another type now; what is no item id is no tick
        $gone = array_filter(array_map('strval', array_keys($seen)), 'is_long_id');
        return ['rows' => $rows, 'stale' => [...$stale, ...$gone], 'left' => $left];
    }

    /**
     * Writes the box of each writable() row into the item's data box field, nothing else; other rows
     * are skipped. An exception from storeItem() ends the run with the rows before it written.
     * @param list<MatchRow> $rows toWrite()['rows'] of this object's report()
     * @return array{written: int, failed: array<string, string>} failed: item id => message, translated
     *                                                            (_m()) and raw - escape it (safe())
     * @throws \RuntimeException see checkSettings() - nothing is written
     * @throws \InvalidArgumentException a row of an item items() did not read - nothing is written
     */
    public function write(array $rows): array
    {
        $this->checkSettings();
        // a row without a box would clear the field, a same one write nothing new
        $rows = array_filter($rows, static fn(MatchRow $row): bool => $row->writable());
        foreach ($rows as $row) {
            if (!isset($this->loaded[(string) $row->itemId])) {
                // storeItem() would move another slice's item into this one
                throw new \InvalidArgumentException('Only items the report has read are written');
            }
        }
        $written = 0;
        $failed  = [];
        try {
            $this->whileReadable(function () use ($rows, &$written, &$failed): void {
                foreach ($rows as $row) {
                    $item_id = (string) $row->itemId;
                    $content = new ItemContent();
                    $content->setSliceID($this->config->sliceId());
                    $content->setItemID($item_id);
                    $content->setAaValue($this->config->dsField(), new AA_Value($row->newDs));
                    ItemContent::lastErr(0, '');    // storeItem() leaves the message of an earlier failure
                    if ($this->store($content, self::STORE_MODE, ItemContent::FLAG_JUST_STORE)) {
                        ++$written;
                    } else {
                        $failed[$item_id] = ItemContent::lastErrMsg() ?: _m('The item was not stored');
                    }
                }
            });
        } finally {
            if ($written > 0) {
                $this->invalidate();
            }
        }
        return ['written' => $written, 'failed' => $failed];
    }

    /**
     * @param ?int $holder when not got: the connection that holds it, null = no lock at all (DB_AA::lock())
     * @return bool got it - unlockDownload() then
     */
    protected static function lockDownload(?int &$holder = null): bool
    {
        return DB_AA::lock(self::DOWNLOAD_LOCK, 0, $holder);
    }

    protected static function unlockDownload(): void
    {
        DB_AA::unlock(self::DOWNLOAD_LOCK);
    }

    protected static function index(string $file): RegisterIndex
    {
        return new RegisterIndex($file);
    }

    /** the newest download attempt up to $now that is younger than MIN_DOWNLOAD_AGE, 0 = none */
    protected static function lastAttempt(int $now): int
    {
        $where = [['type', self::LOG_EVENT], ['time', $now - self::MIN_DOWNLOAD_AGE, '>'], ['time', $now, '<='], ['selector', 'download']];
        return (int) DB_AA::select1('last', 'SELECT MAX(`time`) AS last FROM `log`', $where);
    }

    /** before the download, as a failing one counts too; only an edit of AA_Log::isLogable() drops it */
    protected static function recordAttempt(): void
    {
        \AA_Log::write(self::LOG_EVENT, 'download', (string) constant('AA_ID'));
    }

    /** @return list<string> long ids of the slice's active items, by name */
    protected function itemIds(): array
    {
        // a sort, as QueryZids() wants a condition or a sort
        $set = new AA_Set([$this->config->sliceId()], null, [[$this->config->nameField() => 'a']], \AA_BIN_ACTIVE);
        return array_map('strval', $set->query()->longids());
    }

    /**
     * Ignores the slice's reading password: the State Check runs from cron, and only the configured
     * fields are read.
     * @param list<string> $ids
     * @return array<array-key, mixed> GetItemContent() of the configured fields
     */
    protected function loadContent(array $ids): array
    {
        return GetItemContent(new zids($ids, 'l'), false, true, array_unique(array_filter($this->fields())));
    }

    /** @param list<bool> $flags see ItemContent::storeItem() */
    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        return $content->storeItem($mode, $flags) !== false;
    }

    protected function invalidate(): void
    {
        \AA::Pagecache()->invalidateFor($this->config->sliceId());
    }

    /** @throws \RuntimeException the slice is gone */
    protected function sliceFields(): \AA_Fields
    {
        $slice = \AA_Slice::getModule($this->config->sliceId());
        if (!($slice instanceof \AA_Slice)) {
            throw new \RuntimeException('The slice of the pairing is gone');
        }
        return $slice->getFields();
    }

    /**
     * storeItem() reads the item before the update with the reading password of the request: without
     * the slice's one every field reads as "Missing Reading Password" and the history records them
     * all as changed. Open for $write only - a password the request learnt would ride in the token of
     * an {ajax} to later requests.
     * @param \Closure(): void $write
     */
    protected function whileReadable(\Closure $write): void
    {
        \AA_Credentials::singleton()->withSlicesOpen([$this->config->sliceId()], $write);
    }

    /** @return array{name: string, ico: string, ds: string, type: string} the configured field ids, '' = none */
    private function fields(): array
    {
        return ['name' => $this->config->nameField(), 'ico' => $this->config->icoField(), 'ds' => $this->config->dsField(), 'type' => $this->config->typeField()];
    }

    /**
     * The settings were checked when saved, the slice's fields may have changed since: a deleted box
     * field would take nothing though every row counted as written, a protected field would show.
     * @throws \RuntimeException the settings do not fit the fields (the messages of Config::errors(),
     *                           translated and raw), or the slice is gone
     */
    private function checkSettings(): void
    {
        $errors = Config::errors($this->config->values(), $this->sliceFields());
        if ($errors) {
            throw new \RuntimeException(implode(' ', $errors));
        }
    }

    /** a stored box as the statuses compare it, hashed; the hash of none too */
    private static function storedHash(string $ds): string
    {
        return substr(md5(strtolower(trim($ds))), 0, 12);
    }
}
