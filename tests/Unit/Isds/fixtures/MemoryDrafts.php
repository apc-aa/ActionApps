<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Drafts;
use ItemContent;

/**
 * Drafts over items in memory: what a store keeps, every write with its mode, the locks, the
 * campaigns that were queued once and a lock another request holds
 */
final class MemoryDrafts extends Drafts
{
    /** @var array<string, array{slice: string, fields: array<string, array{string, int}>}> item id => slice and field => value, flag */
    public array $items = [];
    /** @var list<array{mode: string, id: string, slice: string, fields: array<string, array{string, int}>, flags: list<bool>, open: bool}> */
    public array $writes = [];
    /** @var list<string> the campaigns with a run */
    public array $runs = [];
    /** another request holds every lock */
    public bool $lockedByOther = false;
    /** @var list<string> each lock and unlock by its name */
    public array $lockLog = [];

    private bool $open = false;

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

    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        $fields = [];
        foreach ($content->getContent() as $field => $values) {
            if (!in_array($field, ['id..............', 'unpacked_id.....', 'slice_id........'], true)) {
                $fields[$field] = [(string) $values[0]['value'], (int) ($values[0]['flag'] ?? 0)];
            }
        }
        $id             = strtolower($content->getItemID());
        $slice          = $content->getSliceID();
        $this->writes[] = ['mode' => $mode, 'id' => $id, 'slice' => $slice, 'fields' => $fields, 'flags' => $flags, 'open' => $this->open];
        if (($mode === 'insert_if_new') && isset($this->items[$id])) {
            ItemContent::lastErr(\ITEMCONTENT_ERROR_DUPLICATE, 'not stored');

            return false;
        }
        // storeItem() moves the item into the slice it is given
        $this->items[$id] = ['slice' => $slice, 'fields' => $fields + ($this->items[$id]['fields'] ?? [])];

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
            $content[$field] = [['value' => $value, 'flag' => $flag]];
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
