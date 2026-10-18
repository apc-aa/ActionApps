<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\IO\DB\DB_AA;
use AA\Isds\Schema;
use AA\Isds\SendItems;
use AA_Credentials;
use ItemContent;

/**
 * SendItems over items in memory, writing through its real create(), mark() and campaign(): what a
 * store keeps, every write with the transaction and the open slices it ran in. Its slices are those
 * of Sending.
 */
final class MemorySendItems extends SendItems
{
    /** @var array<string, array{slice: string, fields: array<string, string>}> item id in small letters => what a store keeps */
    public array $stored = [];
    /** @var list<array{mode: string, id: string, fields: array<string, string>, tx: bool, open: array<string, bool>}> */
    public array $writes = [];
    /** @var ?\Closure(): void runs at each write after it is listed, before it is stored - a step fails or dies there */
    public ?\Closure $onWrite = null;

    /** @param string $campaign the long id of the campaign item, there as queued */
    public function __construct(string $campaign)
    {
        parent::__construct(Sending::CAMPAIGN, Sending::MESSAGE, $campaign);
        $this->stored[$campaign] = ['slice' => Sending::CAMPAIGN, 'fields' => [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_QUEUED]];
    }

    /** @return list<string> ids of the writes of $mode */
    public function written(string $mode): array
    {
        return array_column(array_filter($this->writes, static fn(array $w): bool => $w['mode'] === $mode), 'id');
    }

    /** @return string $field of the item $id as stored */
    public function field(string $id, string $field): string
    {
        return $this->stored[strtolower($id)]['fields'][$field] ?? '';
    }

    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        $fields = [];
        foreach ($content->getContent() as $field => $values) {
            if (!in_array($field, ['id..............', 'unpacked_id.....', 'slice_id........'], true)) {
                $fields[$field] = (string) $values[0]['value'];
            }
        }
        $id          = strtolower($content->getItemID());
        $credentials = AA_Credentials::singleton();
        $open        = [Sending::CAMPAIGN => $credentials->checkSlice(Sending::CAMPAIGN), Sending::MESSAGE => $credentials->checkSlice(Sending::MESSAGE)];
        $this->writes[] = ['mode' => $mode, 'id' => $id, 'fields' => $fields, 'tx' => DB_AA::inTransaction(), 'open' => $open];
        if ($this->onWrite) {
            ($this->onWrite)();
        }
        if (($mode === 'insert_if_new') && isset($this->stored[$id])) {
            ItemContent::lastErr(\ITEMCONTENT_ERROR_DUPLICATE, 'not stored');
            return false;
        }
        $this->stored[$id] = ['slice' => $content->getSliceID(), 'fields' => $fields + ($this->stored[$id]['fields'] ?? [])];
        return true;
    }

    // as GetItemContent(): keyed by the id in small letters, the slice of every item
    protected function content(string $id, string $field): array
    {
        $id   = strtolower($id);
        $item = $this->stored[$id] ?? null;
        if (is_null($item)) {
            return [];
        }
        return [$id => ['u_slice_id......' => [['value' => $item['slice']]]] + (isset($item['fields'][$field]) ? [$field => [['value' => $item['fields'][$field]]]] : [])];
    }
}
