<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\IO\DB\DB_AA;
use AA_Metabase;
use Generator;
use InvalidArgumentException;
use zids;

/** Source over this AA's database - DB_AA for rows, GetItemContent() for items (the one item path). */
final class DbSource implements Source
{
    /** GetItemContent() ids per call - bounds the IN list and the memory of one call */
    private const ITEM_CHUNK = 500;

    /** @throws InvalidArgumentException a table no package is made of, or no condition (a whole table) */
    public function select(string $table, array $where): array
    {
        if (!in_array($table, (new Schema())->rowTables(), true) || !$where) {
            throw new InvalidArgumentException("DbSource: no rows of `$table` " . ($where ? 'in a package' : 'without a condition'));
        }
        $rows = DB_AA::select([], 'SELECT * FROM ' . DB_AA::quoteIdentifier($table), $where);
        foreach (AA_Metabase::getPacked($table) as $column) {
            foreach ($rows as $i => $row) {
                if (isset($row[$column])) {
                    $rows[$i][$column] = bin2hex((string) $row[$column]);   // unpack_id() would turn NULL into ''
                }
            }
        }
        return $rows;
    }

    /** @return Generator<int, array{id: string, content: array<string, list<array<string, mixed>>>}> */
    public function items(string $slice_id, bool $all_bins): Generator
    {
        $where = [['slice_id', $slice_id, 'l'], ...($all_bins ? [] : [['status_code', 1, 'i']])];
        $ids   = DB_AA::select('unpackid', 'SELECT LOWER(HEX(' . DB_AA::quoteIdentifier('id') . ')) AS unpackid FROM ' . DB_AA::quoteIdentifier('item'), $where);
        foreach (array_chunk($ids, self::ITEM_CHUNK) as $chunk) {
            foreach (GetItemContent(new zids($chunk, 'l'), false, true) as $id => $content) {
                yield ['id' => (string) $id, 'content' => $content];
            }
        }
    }
}
