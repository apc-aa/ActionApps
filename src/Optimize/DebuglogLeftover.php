<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA_Log;

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

    public static function name(): string
    {
        return _m("Find forgotten {debuglog} expressions");
    }

    public static function description(): string
    {
        return _m("Lists views, slice/module/site settings, fields, site spots, alias functions and Code slice items which contain {debuglog}, and the DEBUG log rows written in the last 24 hours. Remove the expression once the debugging is over - it logs on every render of the page.");
    }

    /** a LIKE scan over every code column (CodePoints) - not index-only, so not a basic check */
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

        foreach (CodePoints::containing('{debuglog') as $row) {
            $found++;
            $this->message([
                'where' => "$row[table].$row[column]",
                'id'    => Adminlink::linkByTable($row['table'], $row['column'], $row) ?: safe($row['iid']),
                'code'  => '<small>' . self::snippet($row['txt']) . '</small>',
            ]);
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
