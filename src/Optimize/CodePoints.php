<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA_Metabase;
use AA_Object;

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

    /** Is $needle anywhere in the code - containing() with one LIMIT 1 probe per column, for a yes/no */
    public static function contains(string $needle): bool
    {
        foreach (self::TABLES as $table => $ids) {
            foreach (self::columns($table) as $column) {
                if (DB_AA::test($table, [["`$column`", $needle, 'CONTAIN']])) {
                    return true;
                }
            }
        }
        $code_slices = DB_AA::select('lid', 'SELECT LOWER(HEX(`id`)) AS lid FROM `slice`', [['type', '___Code', 'BEGIN']]);
        return $code_slices && DB_AA::test(['content', 'item'], [['content.text', $needle, 'CONTAIN'], ['content.item_id', 'item.id', 'j'], ['item.slice_id', $code_slices, 'l']]);
    }

    /** the code values containing $needle which $keep accepts
     *  @param callable(string): bool $keep
     *  @return list<array{table: string, column: string, iid: string, sid?: string, txt: string}> */
    public static function matching(string $needle, callable $keep): array
    {
        $rows = [];
        foreach (self::containing($needle) as $row) {
            if ($keep($row['txt'])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** $rows of containing() with the module each belongs to ('module')
     *  @param list<array{table: string, column: string, iid: string, sid?: string, txt: string}> $rows
     *  @return list<array{table: string, column: string, iid: string, sid?: string, txt: string, module: string}> */
    public static function withModules(array $rows): array
    {
        $objects = array_column(array_filter($rows, static fn(array $row): bool => $row['table'] === 'object_text'), 'iid');
        $owners  = $objects ? AA_Object::loadProperties(array_values(array_unique($objects)), 'aa_owner') : [];
        foreach ($rows as $key => $row) {
            $rows[$key]['module'] = self::moduleOf($row, $owners);
        }
        return $rows;
    }

    /** The module a CodePoints row belongs to: a view, field, spot or Code item names it in sid, a row of
     *  slice / module / site / polls is the module, an object belongs to its owner.
     *  @param array{table: string, iid: string, sid?: string} $row
     *  @param array<string, list<string>> $owners  object id => [aa_owner] (AA_Object::loadProperties())
     */
    public static function moduleOf(array $row, array $owners): string
    {
        return (string) ($row['sid'] ?? ($row['table'] === 'object_text' ? ($owners[$row['iid']][0] ?? '') : $row['iid']));
    }

    /** module id => name of those of $module_ids not deleted - deleted modules are not served
     *  @param list<string> $module_ids
     *  @return array<string, string> */
    public static function liveModules(array $module_ids): array
    {
        $ids = array_values(array_unique(array_filter($module_ids, 'is_long_id')));
        return $ids ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', $ids, 'l'], ['deleted', 0, 'i']]) : [];
    }

    /** HTML link to the admin page editing the code of the row, its location as text where there is none */
    public static function where(array $row): string
    {
        return Adminlink::linkByTable($row['table'], $row['column'], $row) ?: safe("$row[table].$row[column] $row[iid]");
    }

    /** $code without the {#comments} AA removes, nested braces included. One pass over the braces: a regex
     *  for nested braces backtracks quadratically on unclosed {#, which any template author can write.
     *  An unclosed comment is printed, so what it holds counts. */
    public static function withoutComments(string $code): string
    {
        if (!str_contains($code, '{#')) {
            return $code;
        }
        $open = [];   // positions of the unclosed {
        $cut  = [];   // comment start => its }
        $len  = strlen($code);
        for ($i = strcspn($code, '{}'); $i < $len; $i += 1 + strcspn($code, '{}', $i + 1)) {
            if ($code[$i] === '{') {
                $open[] = $i;
            } elseif ($open) {
                $start = array_pop($open);
                if ($i === $start + 1) {
                    $open = [];   // AA never expands an empty {}, so no brace around it closes
                } elseif ($code[$start + 1] === '#') {
                    $cut[$start] = $i;
                }
            }
        }
        ksort($cut);   // inner comments close first
        $out = '';
        $pos = 0;
        foreach ($cut as $start => $end) {
            if ($start >= $pos) {   // not inside a comment cut already
                $out .= substr($code, $pos, $start - $pos);
                $pos  = $end + 1;
            }
        }
        return $out . substr($code, $pos);
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
