<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** Until 2026-09 this class (as AddOptimizeCronEntry) installed the AA cron
 *  entry `misc/optimize.php key=<first five characters of DB_PASSWORD>`.
 *  Table optimization is now the queue task AA\Later\OptimizeTables, so the
 *  entry - and the piece of the DB password it carried - has to go.
 */
class RemoveOptimizeCronEntry extends AbstractOptimize
{
    private const SCRIPT = 'misc/optimize.php';

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string
    {
        return _m("Removes the obsolete cron entry for misc/optimize.php");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string
    {
        return _m("Table optimization runs as the queue task OptimizeTables since 2026-09. The old AA cron entry called misc/optimize.php with a piece of the database password in its parameters - this removes the entry (and the script no longer exists).");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array
    {
        return ['sql_update'];
    }

    /** Test function @return bool */
    public function test(): bool
    {
        if (DB_AA::count('cron', [['script', self::SCRIPT]]) > 0) {
            $this->message(_m("Obsolete AA cron entry for %1 is still installed", [self::SCRIPT]));
            return false;
        }
        return true;
    }

    /** Main update function @return bool */
    public function repair(): bool
    {
        $deleted = DB_AA::delete('cron', [['script', self::SCRIPT]]);
        $this->message(_m("AA cron entry for %1 deleted (%2 rows)", [self::SCRIPT, (int) $deleted]));
        return true;
    }
}
