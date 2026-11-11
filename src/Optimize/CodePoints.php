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
 *  of Code slices - and besides them the item fields shown by the alias function f_y or edited
 *  by the code widget in any slice. The Alerts e-mails only on request ($mails): their code
 *  is no page, a check about pages would report them wrongly. For the checks which look for
 *  an expression in all of them.
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

    /** the Alerts e-mails, the same way as TABLES */
    private const MAILS = ['email' => ['iid' => '`id`', 'sid' => 'LOWER(HEX(`owner_module_id`))']];

    /** items whose content one query of texts() reads - the memory stays bounded on any slice */
    private const ITEMS_CHUNK = 500;

    /** Every code value containing $needle - a LIKE scan over every code column; cheap on any
     *  real install (the code tables are small) but not index-only, so for extended checks.
     *
     *  @return \Generator<int, array{table: string, column: string, iid: string, sid?: string, txt: string}>
     *          a row Adminlink::linkByTable($row['table'], $row['column'], $row) links to
     */
    public static function containing(string $needle, bool $mails = false): \Generator
    {
        foreach (self::tables($mails) as $table => $ids) {
            foreach (self::columns($table) as $column) {
                $query = "SELECT $ids[iid] AS iid" . (isset($ids['sid']) ? ", $ids[sid] AS sid" : '') . ", `$column` AS txt FROM `$table`";
                foreach (DB_AA::select([], $query, [["`$column`", $needle, 'CONTAIN']]) as $row) {
                    yield self::row($table, $column, $row);
                }
            }
        }

        $query = 'SELECT LOWER(HEX(`content`.`item_id`)) AS iid, LOWER(HEX(`item`.`slice_id`)) AS sid, `content`.`text` AS txt FROM `content`, `item`';
        foreach (self::itemScopes() as $scope) {
            foreach (DB_AA::select([], $query, array_merge([['content.item_id', 'item.id', 'j']], self::scopeWhere($scope), [['content.text', $needle, 'CONTAIN']])) as $row) {
                yield self::row('content', 'text', $row);
            }
        }
    }

    /** Every code text, for a check looking for many needles: one read of all the code instead of a scan
     *  per needle. One column, or the code fields of ITEMS_CHUNK items, is in memory at a time - the
     *  f_y fields of a big install hold hundreds of MB.
     *  @return \Generator<int, string> */
    public static function texts(bool $mails = false): \Generator
    {
        foreach (self::tables($mails) as $table => $ids) {
            foreach (self::columns($table) as $column) {
                foreach (DB_AA::select('txt', "SELECT `$column` AS txt FROM `$table`", [["`$column`", '', 'FILLED']]) as $txt) {
                    yield (string) $txt;
                }
            }
        }
        foreach (self::itemScopes() as $scope) {
            foreach ($scope['slices'] as $sid) {
                $items = DB_AA::select('iid', 'SELECT LOWER(HEX(`id`)) AS iid FROM `item`', [['slice_id', $sid, 'l']]);
                foreach (array_chunk($items, self::ITEMS_CHUNK) as $chunk) {
                    $where = [['item_id', $chunk, 'l'], ['text', '', 'FILLED']];
                    if ($scope['fields'] !== null) {
                        $where[] = ['field_id', $scope['fields']];
                    }
                    foreach (DB_AA::select('txt', 'SELECT `text` AS txt FROM `content`', $where) as $txt) {
                        yield (string) $txt;
                    }
                }
            }
        }
    }

    /** @return array<string, array<string, string>> */
    private static function tables(bool $mails): array
    {
        return $mails ? self::TABLES + self::MAILS : self::TABLES;
    }

    /** @param array{slices: string[], fields: ?string[]} $scope
     *  @return list<array> the DB_AA $where of the scope over `content`, `item` */
    private static function scopeWhere(array $scope): array
    {
        return ($scope['fields'] === null)
            ? [['item.slice_id', $scope['slices'], 'l']]
            : [['item.slice_id', $scope['slices'], 'l'], ['content.field_id', $scope['fields']]];
    }

    /** The item fields holding code: all the fields of the Code slices (slice type "___Code...", the way
     *  aafinder's "Code Items" checkbox scopes them; fields null), and the fields with the alias function
     *  f_y or the code widget cod - one scope for the slices sharing the same such fields, so no other
     *  field of a slice is read.
     *  @return list<array{slices: string[], fields: ?string[]}> */
    private static function itemScopes(): array
    {
        $scopes      = [];
        $code_slices = DB_AA::select('lid', 'SELECT LOWER(HEX(`id`)) AS lid FROM `slice`', [['type', '___Code', 'BEGIN']]);
        if ($code_slices) {
            $scopes[] = ['slices' => $code_slices, 'fields' => null];
        }
        $fields = [];
        foreach ([['alias1_func', 'f_y'], ['alias2_func', 'f_y'], ['alias3_func', 'f_y'], ['input_show_func', 'cod']] as [$column, $prefix]) {
            foreach (DB_AA::select([], 'SELECT LOWER(HEX(`slice_id`)) AS sid, `id` FROM `field`', [[$column, $prefix, 'BEGIN']]) as $row) {
                if (!in_array($row['sid'], $code_slices, true)) {
                    $fields[(string) $row['sid']][(string) $row['id']] = true;
                }
            }
        }
        $by_fields = [];
        foreach ($fields as $sid => $ids) {
            ksort($ids);
            $by_fields[json_encode(array_keys($ids))][] = $sid;
        }
        foreach ($by_fields as $ids => $slices) {
            $scopes[] = ['slices' => $slices, 'fields' => json_decode((string) $ids, true)];
        }
        return $scopes;
    }

    /** Is $needle anywhere in the code - containing() with one LIMIT 1 probe per column, for a yes/no */
    public static function contains(string $needle, bool $mails = false): bool
    {
        foreach (self::tables($mails) as $table => $ids) {
            foreach (self::columns($table) as $column) {
                if (DB_AA::test($table, [["`$column`", $needle, 'CONTAIN']])) {
                    return true;
                }
            }
        }
        foreach (self::itemScopes() as $scope) {
            if (DB_AA::test(['content', 'item'], array_merge([['content.text', $needle, 'CONTAIN'], ['content.item_id', 'item.id', 'j']], self::scopeWhere($scope)))) {
                return true;
            }
        }
        return false;
    }

    /** the code values containing $needle which $keep accepts
     *  @param callable(string): bool $keep
     *  @return list<array{table: string, column: string, iid: string, sid?: string, txt: string}> */
    public static function matching(string $needle, callable $keep, bool $mails = false): array
    {
        $rows = [];
        foreach (self::containing($needle, $mails) as $row) {
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
