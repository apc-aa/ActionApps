<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\IO\DB\DB_AA;
use AA_Object;
use AA_Property;

/**
 * The readers a sending goes to, frozen when it starts. An object of its own, not a part of the run's
 * record: every step reads and writes that record whole, and a list of thousands would go with it.
 * Ids are stored concatenated, PER_ROW a row, as one row per id would be thousands of rows.
 */
final class Recipients extends AA_Object
{
    /** ids a row: 512 KiB of text */
    public const PER_ROW = 16384;

    /** @var list<string> long ids, concatenated */
    protected $ids = [];

    public static function getClassProperties(): array
    {
        return [
            'ids' => new AA_Property('ids', 'ids', 'text', true, true, 'text'),
        ];
    }

    /**
     * Stores $readerIds, owned by the item sent - in the caller's transaction, so that they commit with
     * the run that sends to them; without one, in a transaction of its own
     * @param array<mixed> $readerIds long ids of the readers, in the order of sending
     * @return string the id of the list
     * @throws \InvalidArgumentException $itemId or a reader is no long id in small letters
     */
    public static function freeze(string $itemId, array $readerIds): string
    {
        foreach ([$itemId, ...$readerIds] as $id) {
            // the ids are stored without a separator: another length would shift every id after it
            if (!is_string($id) || !preg_match('/^[0-9a-f]{32}\z/', $id)) {
                throw new \InvalidArgumentException('Only long ids of an item and its readers are frozen');
            }
        }
        $list = new self();
        $list->setNew(new_id(), $itemId);
        $list->ids = array_map(implode(...), array_chunk(array_values($readerIds), self::PER_ROW));
        return (string) DB_AA::transaction(fn(): false|string => $list->save());
    }

    /**
     * The readers of the list $id, in the order frozen. Not AA_Object::load(): it caches per process.
     * @return list<string> long ids; none for an id of no list
     */
    public static function ids(string $id): array
    {
        $rows = [];
        $list = false;
        foreach (DB_AA::select([], 'SELECT property, priority, value FROM `object_text`', [['object_id', $id], ['property', ['ids', 'aa_type']]]) as $row) {
            if ($row['property'] === 'aa_type') {
                $list = ($row['value'] === self::class);
            } else {
                $rows[(int) $row['priority']] = (string) $row['value'];
            }
        }
        if (!$list) {
            return [];
        }
        ksort($rows);
        $ids = implode('', $rows);
        return ($ids === '') ? [] : str_split($ids, 32);
    }
}
