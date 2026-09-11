<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA_Log;
use AA_Metabase;

/** {debuglog:...} writes a DEBUG row to the log on every render of the page it
 *  sits in, bots included: one live example in the Expression Reference wrote
 *  536 rows in six weeks on aadev. Since 2026-09 the expression takes a
 *  "log until" time and stays silent afterwards, but the leftover still costs a
 *  strtotime() per render and hides in a template nobody opens. This lists
 *  where it sits, and which DEBUG rows are still arriving.
 */
class DebuglogLeftover extends AbstractOptimize
{
    /** how far back the log is consulted for fresh DEBUG rows */
    private const FRESH_SECONDS = 86400;

    /** code points, as admin/aafinder.php searches them: table => how to get the
     *  id (and slice) of the row for Adminlink::linkByTable() */
    private const CODE_TABLES = [
        'view'      => ['iid' => '`id`', 'sid' => 'LOWER(HEX(`slice_id`))'],
        'slice'     => ['iid' => 'LOWER(HEX(`id`))'],
        'module'    => ['iid' => 'LOWER(HEX(`id`))'],
        'site'      => ['iid' => 'LOWER(HEX(`id`))'],
        'polls'     => ['iid' => 'LOWER(HEX(`module_id`))'],
        'field'     => ['iid' => '`id`', 'sid' => 'LOWER(HEX(`slice_id`))'],
        'site_spot' => ['iid' => '`spot_id`', 'sid' => 'LOWER(HEX(`site_id`))'],
        'object_text' => ['iid' => '`object_id`'],
    ];

    public static function name(): string
    {
        return _m("Find forgotten {debuglog} expressions");
    }

    public static function description(): string
    {
        return _m("Lists views, slice/module/site settings, fields, site spots, alias functions and Code slice items which contain {debuglog}, and the DEBUG log rows written in the last 24 hours. Remove the expression once the debugging is over - it logs on every render of the page.");
    }

    /** a LIKE scan over every code column; cheap on any real install (the code
     *  tables are small) but not index-only, so not a basic check */
    public static function types(): array
    {
        return ['test', 'check_extended'];
    }

    /** removing the expression is the template author's call - no repair */
    public static function actions(): array
    {
        return ['test'];
    }

    public function test(): bool
    {
        $this->clear_report();
        $found = 0;

        foreach (self::CODE_TABLES as $table => $ids) {
            foreach ($this->codeColumns($table) as $column) {
                $found += $this->reportMatches($table, $column, "SELECT $ids[iid] AS iid" . (isset($ids['sid']) ? ", $ids[sid] AS sid" : '') . ", `$column` AS txt FROM `$table`", [["`$column`", '{debuglog', 'CONTAIN']]);
            }
        }

        // items of Code slices (slice type "___Code..."), the way aafinder's "Code Items" checkbox scopes them
        $code_slices = DB_AA::select('lid', 'SELECT LOWER(HEX(`id`)) AS lid FROM `slice`', [['type', '___Code', 'BEGIN']]);
        if ($code_slices) {
            $found += $this->reportMatches('content', 'text', 'SELECT LOWER(HEX(`content`.`item_id`)) AS iid, LOWER(HEX(`item`.`slice_id`)) AS sid, `content`.`text` AS txt FROM `content`, `item`', [['content.item_id', 'item.id', 'j'], ['item.slice_id', $code_slices, 'l'], ['content.text', '{debuglog', 'CONTAIN']]);
        }

        $fresh = DB_AA::select([], 'SELECT `selector`, COUNT(*) AS cnt, MAX(`time`) AS last FROM `log`', [['type', 'DEBUG'], ['time', now() - self::FRESH_SECONDS, '>=']], ['cnt-'], ['selector'], 20);
        foreach ($fresh as $row) {
            $this->message([
                'where' => _m('DEBUG rows in the last 24 hours'),
                'id'    => '<a href="' . safe(AA_Log::getAdminUrl('DEBUG', (string) $row['selector'])) . '">' . safe((string) $row['selector']) . '</a>',
                'code'  => _m('%1 rows, last %2', [(int) $row['cnt'], date('Y-m-d H:i', (int) $row['last'])]),
            ]);
        }

        if (!$found && !$fresh) {
            $this->message(_m('No {debuglog} found in code points, no DEBUG rows logged in the last 24 hours'));
            return true;
        }
        return false;
    }

    /** text columns of the table, minus packed ids and the module column - aafinder's own selection */
    private function codeColumns(string $table): array
    {
        $columns = array_keys(array_filter(AA::Metabase()->getSearchArray($table), static fn($col) => ($col['operators'] === 'text')));
        return array_diff($columns, AA_Metabase::getPacked($table), [AA_Metabase::getModuleField($table)]);
    }

    /** one report row per matching DB row; returns how many */
    private function reportMatches(string $table, string $column, string $query, array $where): int
    {
        $rows = DB_AA::select([], $query, $where);
        foreach ($rows as $row) {
            $this->message([
                'where' => "$table.$column",
                'id'    => Adminlink::linkByTable($table, $column, $row) ?: safe((string) $row['iid']),
                'code'  => '<small>' . self::snippet((string) $row['txt']) . '</small>',
            ]);
        }
        return count($rows);
    }

    /** the first {debuglog...} with a little context on both sides, HTML-escaped, the match highlighted */
    public static function snippet(string $code, int $context = 60): string
    {
        $pos   = (int) strpos($code, '{debuglog');
        $end   = strpos($code, '}', $pos);
        $end   = ($end === false) ? strlen($code) : $end + 1;
        $start = max(0, $pos - $context);
        $stop  = min(strlen($code), $end + $context);

        return ($start > 0 ? '…' : '')
            . safe(substr($code, $start, $pos - $start))
            . '<mark>' . safe(substr($code, $pos, $end - $pos)) . '</mark>'
            . safe(substr($code, $end, $stop - $end))
            . ($stop < strlen($code) ? '…' : '');
    }
}
