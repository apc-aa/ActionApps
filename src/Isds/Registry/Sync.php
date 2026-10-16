<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\Http\ClientInterface;
use AA_Set;
use AA_Value;
use Files;
use ItemContent;
use zids;

/**
 * Pairs the items of one slice with the register of data boxes and writes the boxes a person ticked
 * into the items' data box field - that field only. The page and the State Check both read through
 * report(), so they count alike.
 *
 * It reads the items past the slice's reading password. Call it only from the slice's admin page,
 * after checking PS_EDIT_ALL_ITEMS (write() changes items for whoever runs it), and from the State
 * Check - never from a template command, a view, the API or any other public entry. State Check
 * findings and their mail give counts (MatchReport::statusCounts()), never item names.
 * Item values and messages go out raw (see MatchReport) - escape them (safe()).
 */
class Sync
{
    /** one copy of the register per install, in its upload directory - shared by the servers, unlike
     *  the system temp directory; the register is public open data */
    public const REGISTER_FILE = 'isds-seznam-ds-ovm.xml';

    /** rows one write() takes - each is a storeItem() of its own; the rest waits for the next submit */
    public const MAX_WRITE = 500;

    /** items GetItemContent() reads at once */
    private const CHUNK = 500;

    /**
     * As "Modify items" with "Silent" ticked: only the field is written - no events (an ITEM_UPDATED
     * mail per item), no feeding, no last_edit, no computed fields; the history and the item log
     * still record the change and who made it, unless the slice keeps no history. The page cache is
     * invalidated once, after all rows.
     */
    private const STORE_MODE = 'update_silent';

    /** @var array<string, true> the items the last items() read - write() changes no other */
    private array $loaded = [];

    /** @param Config $config one errors() accepts for the slice's fields as they are now */
    public function __construct(private readonly Config $config) {}

    /**
     * @throws \RuntimeException the upload directory cannot be created
     */
    public static function registerFile(): string
    {
        $dir = Files::aadestinationDir();
        if ($dir === false) {
            throw new \RuntimeException(Files::lastErrMsg());
        }
        return $dir . '/' . self::REGISTER_FILE;
    }

    /**
     * Downloads the register into registerFile(); a failed download keeps the previous file.
     * @return string the file
     * @throws \RuntimeException see SdsList::download()
     */
    public static function download(?ClientInterface $http = null): string
    {
        return SdsList::download($http ?? SdsList::client(), self::registerFile());
    }

    /**
     * The time and size of the register, so a new download changes it; '' when there is none yet. A
     * page sends it with its form and refuses the submit when the register changed since the person
     * looked at the rows.
     */
    public static function registerStamp(): string
    {
        $file = self::registerFile();
        clearstatcache(true, $file);
        $stat = @stat($file);
        return ($stat === false) ? '' : $stat['mtime'] . '-' . $stat['size'];
    }

    /**
     * The boxes of the downloaded register; iterate them before changing anything (SdsList::fromXml()).
     * @return \Generator<int, Box>
     */
    public static function registerBoxes(): \Generator
    {
        return SdsList::fromXml(self::registerFile());
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
            if (self::writable($row) && ($row->method === MatchRow::BY_ICO) && !$row->icoMismatch && !isset($shared[$row->newDs])) {
                $ticked[] = (string) $row->itemId;
            }
        }
        return $ticked;
    }

    /**
     * The rows to write for the boxes a person ticked: those whose row in this request's report()
     * still offers the very box the person saw, as a new or changed one - another editor may have
     * changed the item, or the register its box, since. The box written is the register's (the row's);
     * the posted one only has to equal it. At most $cap rows, in report order.
     * @param array<array-key, mixed> $ticked item id => the box id the person saw, as a form posts it
     * @return array{rows: list<MatchRow>, stale: list<string>} stale: the ticked items whose row offers
     *         another box or none now, or which are gone - a page says they changed meanwhile. A ticked
     *         item in neither list has the box already, waits for the next submit beyond $cap, or is
     *         no tick: its posted box is no text, or '' for a row that still has no box (unmatched,
     *         ambiguous).
     */
    public static function toWrite(MatchReport $report, array $ticked, int $cap = self::MAX_WRITE): array
    {
        $seen = [];
        foreach ($ticked as $id => $box) {
            if (is_string($box)) {
                $seen[(string) $id] = strtolower(trim($box));
            }
        }
        $rows  = [];
        $stale = [];
        foreach ($report->rows as $row) {
            $id = (string) $row->itemId;
            if (!isset($seen[$id])) {
                continue;
            }
            if ($row->newDs !== $seen[$id]) {
                $stale[] = $id;
            } elseif (self::writable($row) && (count($rows) < $cap)) {
                $rows[] = $row;
            }
            unset($seen[$id]);
        }
        // items no longer paired: deleted, of another type now; what is no item id is no tick
        $gone = array_filter(array_map('strval', array_keys($seen)), 'is_long_id');
        return ['rows' => $rows, 'stale' => [...$stale, ...$gone]];
    }

    /**
     * Writes the box of each row into the item's data box field, nothing else. An exception from
     * storeItem() ends the run with the rows before it written.
     * @param list<MatchRow> $rows toWrite()['rows'] of this object's report()
     * @return array{written: int, failed: array<string, string>} failed: item id => message, translated
     *                                                            (_m()) and raw - escape it (safe())
     * @throws \RuntimeException see checkSettings() - nothing is written
     * @throws \InvalidArgumentException a row without a box, or of an item items() did not read - nothing is written
     */
    public function write(array $rows): array
    {
        $this->checkSettings();
        foreach ($rows as $row) {
            if (!isset($this->loaded[(string) $row->itemId])) {
                // storeItem() would move another slice's item into this one
                throw new \InvalidArgumentException('Only items the report has read are written');
            }
            if ($row->box === null) {
                throw new \InvalidArgumentException('A row without a box would clear the field');
            }
        }
        $this->allowReading();
        $written = 0;
        $failed  = [];
        try {
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
        } finally {
            if ($written > 0) {
                $this->invalidate();
            }
        }
        return ['written' => $written, 'failed' => $failed];
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
     * all as changed.
     */
    protected function allowReading(): void
    {
        \AA_Credentials::singleton()->loadFromSlice($this->config->sliceId());
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

    private static function writable(MatchRow $row): bool
    {
        return ($row->box !== null) && in_array($row->status, [MatchRow::NEW, MatchRow::CHANGED], true);
    }
}
