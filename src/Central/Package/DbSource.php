<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\IO\DB\DB_AA;
use AA_Metabase;
use Generator;
use InvalidArgumentException;
use zids;

/** Source over this AA's database - DB_AA for rows and the object store, GetItemContent() for items
 *  (the one item path). */
final class DbSource implements Source
{
    /** GetItemContent() ids per call - bounds the IN list and the memory of one call */
    private const ITEM_CHUNK = 500;

    /** GetItemContent() leaves out every field starting so (include/util.php) - FIELDID_2FA_SECRET and any
     *  other secret; the items take back exactly those, so none is lost */
    private const SECRET_PREFIX = 'secret';

    /** tables read beside Schema::rowTables(): the owner and the setting values of a module travel in a transfer */
    private const OTHER_TABLES = ['slice_owner', 'content'];

    /** object ids per statement (IN list) */
    public const OBJECT_CHUNK = 500;

    /** properties AA_Object::save() writes as strings - to object_text only */
    private const HELPER_PROPERTIES = ['aa_type', 'aa_owner', 'aa_name', 'aa_subobjects'];

    /** @throws InvalidArgumentException a table no package is made of, or no condition (a whole table) */
    public function select(string $table, array $where): array
    {
        if (!in_array($table, [...(new Schema())->rowTables(), ...self::OTHER_TABLES], true) || !$where) {
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

    /** @throws InvalidArgumentException a cursor that is no unpacked id */
    public function itemIds(string $slice_id, bool $all_bins, string $after, int $limit): array
    {
        if (($after !== '') && !is_long_id($after)) {
            throw new InvalidArgumentException('DbSource: the cursor is no item id');
        }
        $id    = DB_AA::quoteIdentifier('id');
        $where = [['slice_id', $slice_id, 'l'], ...($all_bins ? [] : [['status_code', 1, 'i']]), ...(($after === '') ? [] : [['id', $after, 'l>']])];
        // LIMIT 0 reads nothing - select() would read 0 rows as no LIMIT at all
        return ($limit > 0) ? DB_AA::select('unpackid', "SELECT LOWER(HEX($id)) AS unpackid FROM " . DB_AA::quoteIdentifier('item'), $where, [$id], null, $limit) : [];
    }

    public function itemCount(string $slice_id, bool $all_bins, int $edited_after = 0): int
    {
        $where = [['slice_id', $slice_id, 'l'], ...($all_bins ? [] : [['status_code', 1, 'i']]), ...($edited_after ? [['last_edit', $edited_after, '>']] : [])];
        return DB_AA::count('item', $where);
    }

    /** QueryZids() as views select, one condition a query within the ids the conditions before kept: with
     *  neverAllItems a condition it drops leaves no id - among several, the others would be all that counts */
    public function matching(string $slice_id, ItemFilter $filter, array $ids, int $edited_after = 0): array
    {
        if ($ids && $edited_after) {
            $ids = DB_AA::select('unpackid', 'SELECT LOWER(HEX(' . DB_AA::quoteIdentifier('id') . ')) AS unpackid FROM ' . DB_AA::quoteIdentifier('item'), [['id', $ids, 'l'], ['last_edit', $edited_after, '>']]);
        }
        foreach ($ids ? $filter->conditions() : [] as $c) {
            $cond = ['value' => $c['value'], 'operator' => $c['operator']] + array_fill_keys($c['fields'], 1);
            $ids  = array_map('strtolower', QueryZids([$slice_id], [$cond], '', 'ALL', 1, new zids($ids, 'l'), 'LIKE')->longids());
            if (!$ids) {
                break;
            }
        }
        return $ids;
    }

    /** @return Generator<int, array{id: string, content: array<string, list<array<string, mixed>>>}> */
    public function items(array $ids, bool $with_secrets): Generator
    {
        foreach (array_chunk($ids, self::ITEM_CHUNK) as $chunk) {
            $contents = GetItemContent(new zids($chunk, 'l'), false, true);
            $sql      = 'SELECT LOWER(HEX(' . DB_AA::quoteIdentifier('item_id') . ')) AS item_id, field_id, text, flag FROM ' . DB_AA::quoteIdentifier('content');
            foreach ($with_secrets ? DB_AA::select([], $sql, [['item_id', $chunk, 'l'], ['field_id', self::SECRET_PREFIX, 'BEGIN']], ['item_id', 'number']) : [] as $row) {
                if (isset($contents[$row['item_id']])) {
                    $contents[$row['item_id']][$row['field_id']][] = ['value' => $row['text'], 'flag' => (int) $row['flag']];
                }
            }
            foreach ($contents as $id => $content) {
                yield ['id' => (string) $id, 'content' => $content];
            }
        }
    }

    public function logins(string $slice_id, bool $all_bins): array
    {
        $sql = 'SELECT ' . DB_AA::quoteIdentifier('content.text') . ' FROM ' . DB_AA::quoteIdentifier('content') . ' INNER JOIN ' . DB_AA::quoteIdentifier('item')
            . ' ON ' . DB_AA::quoteIdentifier('item.id') . ' = ' . DB_AA::quoteIdentifier('content.item_id');
        $where = [['item.slice_id', $slice_id, 'l'], ['content.field_id', ItemLint::USERNAME, 's'], ...($all_bins ? [] : [['item.status_code', 1, 'i']])];
        return array_map('strval', DB_AA::select('', $sql, $where));
    }

    public function objectIds(string $owner_id): array
    {
        $sql = 'SELECT object_id FROM ' . DB_AA::quoteIdentifier('object_text');
        return array_map('strval', DB_AA::select('', $sql, [['property', 'aa_owner', 's'], ['value', $owner_id, 's']]));
    }

    public function objectRows(array $ids, array $properties = []): array
    {
        $rows    = [];
        $helpers = $properties && !array_diff($properties, self::HELPER_PROPERTIES);
        foreach (array_chunk(array_values(array_unique($ids)), self::OBJECT_CHUNK) as $chunk) {
            $where = [['object_id', $chunk, 's'], ...($properties ? [['property', $properties, 's']] : [])];
            foreach ($helpers ? ['object_text'] : ObjectExport::TABLES as $table) {
                $sql = 'SELECT object_id, property, priority, value FROM ' . DB_AA::quoteIdentifier($table);
                foreach (DB_AA::select([], $sql, $where) as $row) {
                    $rows[] = ['table' => $table] + $row;
                }
            }
        }
        return $rows;
    }
}
