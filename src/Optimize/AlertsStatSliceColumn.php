<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;

/** Adds alerts_collection.stat_slice_id - the slice where the opens and clicks of a list's mailings are kept.
 *  Until it exists, the Alerts settings cannot name one and nothing is measured. */
class AlertsStatSliceColumn extends AbstractOptimize
{
    private const TABLE  = 'alerts_collection';
    private const COLUMN = 'stat_slice_id';

    public static function name(): string
    {
        return _m('Column for the statistics slice of Alerts');
    }

    public static function description(): string
    {
        return _m('Adds the column alerts_collection.stat_slice_id, where the Alerts settings keep the slice that counts the opens and clicks of the mailings. Nothing else changes.');
    }

    public static function types(): array
    {
        return ['sql_update'];
    }

    /** before UpdateDbStructure (50), which would otherwise add it among all the differences */
    public static function priority(): int
    {
        return 45;
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    public function test(): bool
    {
        if (self::missing()) {
            $this->message(_m('The column %1 is missing', [self::TABLE . '.' . self::COLUMN]));
            return false;
        }
        $this->message(_m('OK - the column %1 exists', [self::TABLE . '.' . self::COLUMN]));
        return true;
    }

    public function repair(): bool
    {
        if (!self::missing()) {
            return true;
        }
        $sql = AA::Metabase()->tables[self::TABLE]->getColumn(self::COLUMN)->getCreateSql();
        return $this->query('ALTER TABLE `' . self::TABLE . '` ADD ' . trim($sql));
    }

    /** without the table there is nothing to add to - a new install creates it whole */
    private static function missing(): bool
    {
        return DB_AA::exists_table(self::TABLE) && !DB_AA::exists_column(self::TABLE, self::COLUMN);
    }
}
