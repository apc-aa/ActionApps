<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Drafts;
use ItemContent;

/**
 * Drafts over items in memory: what a store keeps, every write with its mode, the locks, the
 * campaigns that were queued once and a lock another request holds; the files PHP received, those
 * read and the upload directory of the campaign slice
 */
final class MemoryDrafts extends Drafts
{
    /** the upload directory of the campaign slice, and its URL */
    public const DIR = '/var/www/upload/' . Sending::CAMPAIGN;
    public const URL = 'https://www.example.cz/upload/' . Sending::CAMPAIGN;

    /** @var array<string, array{slice: string, fields: array<string, array{string, int}>, values?: array<string, list<string>>}> item id => slice, field => first value, flag, and field => the values of a field of none or several */
    public array $items = [];
    /** @var list<array{mode: string, id: string, slice: string, fields: array<string, array{string, int}>, flags: list<bool>, open: bool}> */
    public array $writes = [];
    /** @var list<string> the campaigns with a run */
    public array $runs = [];
    /** another request holds every lock */
    public bool $lockedByOther = false;
    /** @var list<string> each lock and unlock by its name */
    public array $lockLog = [];
    /** @var array<string, string> temporary name => content of each file PHP received in the request */
    public array $received = [];
    /** @var list<string> the temporary names read */
    public array $read = [];
    /** @var array<string, string> path => content of the files in the upload directory */
    public array $disk = [];
    /** the move of the file of this temporary name fails */
    public string $moveFails = '';
    /** storeItem() fails */
    public bool $storeFails = false;
    /** storeItem() throws - a transaction that did not commit */
    public bool $storeThrows = false;
    /** the slice is gone when the URL of a file moved in is asked */
    public bool $urlFails = false;

    private bool $open = false;
    private int $tmpNames = 0;

    /** a draft as an item of $slice, the status and the state given */
    public function draft(string $id, string $slice = Sending::CAMPAIGN, int $status = 1, string $state = 'draft'): void
    {
        $this->items[$id] = ['slice' => $slice, 'fields' => ['status_code.....' => [(string) $status, 0], 'text...........3' => [$state, 0]]];
    }

    /** @return string $field of the item $id as stored */
    public function field(string $id, string $field): string
    {
        return $this->items[$id]['fields'][$field][0] ?? '';
    }

    /** @return list<string> every value of $field of the item $id as stored */
    public function values(string $id, string $field): array
    {
        return $this->items[$id]['values'][$field] ?? (isset($this->items[$id]['fields'][$field]) ? [$this->items[$id]['fields'][$field][0]] : []);
    }

    /** a file PHP received, under a temporary name of its own */
    public function receive(string $content): string
    {
        $tmp                  = '/tmp/php' . (++$this->tmpNames);
        $this->received[$tmp] = $content;

        return $tmp;
    }

    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        $fields = [];
        $all    = [];
        foreach ($content->getContent() as $field => $values) {
            if (!in_array($field, ['id..............', 'unpacked_id.....', 'slice_id........'], true)) {
                $fields[$field] = [(string) ($values[0]['value'] ?? ''), (int) ($values[0]['flag'] ?? 0)];
                $all[$field]    = array_map(static fn(array $v): string => (string) $v['value'], array_values($values));
            }
        }
        $id             = strtolower($content->getItemID());
        $slice          = $content->getSliceID();
        $this->writes[] = ['mode' => $mode, 'id' => $id, 'slice' => $slice, 'fields' => $fields, 'flags' => $flags, 'open' => $this->open];
        if ($this->storeThrows) {
            throw new \RuntimeException('not committed');
        }
        if ($this->storeFails) {
            ItemContent::lastErr(1, 'not stored');

            return false;
        }
        if (($mode === 'insert_if_new') && isset($this->items[$id])) {
            ItemContent::lastErr(\ITEMCONTENT_ERROR_DUPLICATE, 'not stored');

            return false;
        }
        // storeItem() moves the item into the slice it is given
        // a field of one value is kept in fields only, so that a test changes it there
        $multi            = array_filter($all, static fn(array $values): bool => count($values) !== 1);
        $this->items[$id] = ['slice' => $slice, 'fields' => $fields + ($this->items[$id]['fields'] ?? []), 'values' => $multi + array_diff_key($this->items[$id]['values'] ?? [], $all)];

        return true;
    }

    // as AA_Items gives it, with the dates of an active item
    protected function campaign(string $id): ?ItemContent
    {
        $item = $this->items[$id] ?? null;
        if (is_null($item)) {
            return null;
        }
        $content = [
            'unpacked_id.....' => [['value' => $id]],
            'u_slice_id......' => [['value' => $item['slice']]],
            'publish_date....' => [['value' => 1]],
            'expiry_date.....' => [['value' => 4_000_000_000]],
        ];
        foreach ($item['fields'] as $field => [$value, $flag]) {
            $content[$field] = array_map(static fn(string $v): array => ['value' => $v, 'flag' => $flag], $item['values'][$field] ?? [$value]);
        }

        return new ItemContent($content);
    }

    protected function runExists(string $campaign): bool
    {
        return in_array($campaign, $this->runs, true);
    }

    protected function lock(string $name): bool
    {
        $this->lockLog[] = "lock $name";

        return !$this->lockedByOther;
    }

    protected function unlock(string $name): void
    {
        $this->lockLog[] = "unlock $name";
    }

    protected function uploaded(string $tmp): ?string
    {
        $this->read[] = $tmp;

        return $this->received[$tmp] ?? null;
    }

    // as Files::uploadFile() does it: a name some file has gets a number
    protected function move(string $slice, string $tmp, string $name): string
    {
        if (($slice !== Sending::CAMPAIGN) || ($tmp === $this->moveFails) || !isset($this->received[$tmp])) {
            throw new \RuntimeException('not moved');
        }
        $dot  = strrpos($name, '.');
        $path = self::DIR . "/$name";
        for ($i = 1; isset($this->disk[$path]); ++$i) {
            $path = self::DIR . '/' . (($dot === false) ? "{$name}_$i" : substr($name, 0, $dot) . "_$i" . substr($name, $dot));
        }
        $this->disk[$path] = $this->received[$tmp];
        unset($this->received[$tmp]);

        return $path;
    }

    protected function urlOf(string $slice, string $path): string
    {
        if ($this->urlFails) {
            throw new \RuntimeException("No slice $slice");
        }

        return self::URL . substr($path, strlen(self::DIR));
    }

    protected function discard(array $paths): void
    {
        foreach ($paths as $path) {
            unset($this->disk[$path]);
        }
    }

    protected function whileOpen(string $slice, \Closure $fn): mixed
    {
        if ($slice !== Sending::CAMPAIGN) {
            throw new \LogicException("Only the campaign slice is opened, not $slice");
        }
        $this->open = true;
        try {
            return $fn();
        } finally {
            $this->open = false;
        }
    }
}
