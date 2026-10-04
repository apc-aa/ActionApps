<?php

declare(strict_types=1);

namespace Tests\Unit\IO\Fixtures;

use AA\IO\Saver;
use AA_Set;
use ItemContent;
use zids;

/** Saver whose key lookup answers from $existing and whose store records the item instead of
 *  writing it. Everything else still goes to the database handle - e.g. the relation to the source
 *  item of an item stored under a new id (RecordingDb in the tests). */
class RecordingSaver extends Saver
{
    /** @var array<string, string> key values joined by "|" in key-field order => long id the lookup finds */
    public array $existing = [];

    /** @var list<list<string>> the key values of each lookup, in order */
    public array $lookups = [];

    /** @var list<array{id: string, mode: string, flags: array, content: ItemContent}> */
    public array $stored = [];

    /** @var list<string> item ids store() refuses, as a failing storeItem() does */
    public array $refuse = [];

    protected function findByKey(AA_Set $set): zids
    {
        $key             = array_map(static fn(array $cond): string => (string) $cond['value'], $set->getConds());
        $this->lookups[] = $key;
        $id              = $this->existing[implode('|', $key)] ?? null;
        return ($id === null) ? new zids() : new zids([$id], 'l');
    }

    protected function store(ItemContent $content, string $mode, array $flags = []): string|false
    {
        $id = $content->getItemID();
        if (in_array($id, $this->refuse, true)) {
            return false;
        }
        $this->stored[] = ['id' => $id, 'mode' => $mode, 'flags' => $flags, 'content' => $content];
        return $id;
    }

    /** @return list<string> ids of the stored items, in order */
    public function storedIds(): array
    {
        return array_column($this->stored, 'id');
    }
}
