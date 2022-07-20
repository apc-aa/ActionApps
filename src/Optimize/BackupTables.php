<?php

namespace AA\Optimize;

use AA;
use function _m;

/** Restore Data from Backup Tables
 *  This script DELETES all the current tables (slice, item, ...) where we have
 *  bck_table and renames all backup tables (bck_slice, bck_item, ...) to right
 *  names (slice, item, ...).
 **/
class BackupTables extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Backup tables");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("[experimental] ") . _m("Deletes all the current backup tables (bck_slice, bck_item, ...) and copies there live tables (slice, item, ...).");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    function isType($type): bool { return in_array($type, []); }

    public static function actions(): array { return ['repair']; }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $metabase = AA::Metabase();
        $tablenames = $metabase->getTableNames();

        foreach ($tablenames as $tablename) {
            if (substr($tablename, 0, 4) == 'bck_') {
                continue;
            }

            // create backup table
            $this->message(_m('Deleting old backup table bck_%1, if exist.', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `bck_$tablename`");

            $this->message(_m('Creating empty backup table bck_%1.', [$tablename]));
            $this->query("CREATE TABLE `bck_$tablename` LIKE `$tablename`");

            // create new tables that do not exist in database
            // (we need it for next data copy, else it ends up with db error)
            $this->message(_m('Copy data from %1 to bck_%1.', [$tablename]));
            $this->query("INSERT INTO `bck_$tablename` SELECT * FROM `$tablename`");

            $this->message(_m('%1 done.', [$tablename]));

        }
        return true;
    }
}