<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_Metabase;

/** Where template code lives - the code points admin/aafinder.php searches: views, slice,
 *  module and site settings, fields (alias functions), site spots, object_text and the items
 *  of Code slices. For the checks which look for an expression in all of them.
 */
final class CodePoints
{
    /** table => how to get the id (and slice) of the row for Adminlink::linkByTable() */
    private const TABLES = [
        'view'        => ['iid' => '`id`', 'sid' => 'LOWER(HEX(`slice_id`))'],
        'slice'       => ['iid' => 'LOWER(HEX(`id`))'],
        'module'      => ['iid' => 'LOWER(HEX(`id`))'],
        'site'        => ['iid' => 'LOWER(HEX(`id`))'],
        'polls'       => ['iid' => 'LOWER(HEX(`module_id`))'],
        'field'       => ['iid' => '`id`', 'sid' => 'LOWER(HEX(`slice_id`))'],
        'site_spot'   => ['iid' => '`spot_id`', 'sid' => 'LOWER(HEX(`site_id`))'],
        'object_text' => ['iid' => '`object_id`'],
    ];

    /** Every code value containing $needle - a LIKE scan over every code column; cheap on any
     *  real install (the code tables are small) but not index-only, so for extended checks.
     *
     *  @return \Generator<int, array{table: string, column: string, iid: string, sid?: string, txt: string}>
     *          a row Adminlink::linkByTable($row['table'], $row['column'], $row) links to
     */
    public static function containing(string $needle): \Generator
    {
        foreach (self::TABLES as $table => $ids) {
            foreach (self::columns($table) as $column) {
                $query = "SELECT $ids[iid] AS iid" . (isset($ids['sid']) ? ", $ids[sid] AS sid" : '') . ", `$column` AS txt FROM `$table`";
                foreach (DB_AA::select([], $query, [["`$column`", $needle, 'CONTAIN']]) as $row) {
                    yield self::row($table, $column, $row);
                }
            }
        }

        // items of Code slices (slice type "___Code..."), the way aafinder's "Code Items" checkbox scopes them
        $code_slices = DB_AA::select('lid', 'SELECT LOWER(HEX(`id`)) AS lid FROM `slice`', [['type', '___Code', 'BEGIN']]);
        if ($code_slices) {
            $query = 'SELECT LOWER(HEX(`content`.`item_id`)) AS iid, LOWER(HEX(`item`.`slice_id`)) AS sid, `content`.`text` AS txt FROM `content`, `item`';
            foreach (DB_AA::select([], $query, [['content.item_id', 'item.id', 'j'], ['item.slice_id', $code_slices, 'l'], ['content.text', $needle, 'CONTAIN']]) as $row) {
                yield self::row('content', 'text', $row);
            }
        }
    }

    /** @return array{table: string, column: string, iid: string, sid?: string, txt: string} (a view id comes back as int) */
    private static function row(string $table, string $column, array $row): array
    {
        return ['table' => $table, 'column' => $column, 'iid' => (string) $row['iid'], 'txt' => (string) $row['txt']]
            + (isset($row['sid']) ? ['sid' => (string) $row['sid']] : []);
    }

    /** text columns of the table, minus packed ids and the module column - aafinder's own selection */
    private static function columns(string $table): array
    {
        $columns = array_keys(array_filter(AA::Metabase()->getSearchArray($table), static fn($col) => ($col['operators'] === 'text')));
        return array_diff($columns, AA_Metabase::getPacked($table), [AA_Metabase::getModuleField($table)]);
    }
}
