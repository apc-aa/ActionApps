<?php

namespace AA\Optimize;

use AA;
use function _m;

/** Drop all bck_* tables
 *  This script DELETES all the current tables (slice, item, ...) where we have
 *  bck_table and renames all backup tables (bck_slice, bck_item, ...) to right
 *  names (slice, item, ...).
 **/
class DropBackupTables extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Delete backup tables");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Deletes all the current backup tables (bck_slice, bck_item, ...).");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    function isType($type): bool { return in_array($type, []); }

    public static function actions(): array { return ['repair']; }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $current_metabase = new \AA_Metabase();  // call contructor here is OK - we need to load structure from current db, not modify AA ideal strusture defined in AA::Metabase()
        $current_metabase->loadFromDb();
        $tablenames = $current_metabase->getTableNames();

        foreach ($tablenames as $tablename) {
            if (substr($tablename, 0, 4) == 'bck_') {
                continue;
            }

            // create backup table
            $this->message(_m('Deleting old backup table bck_%1, if exist.', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `bck_$tablename`");

            $this->message(_m('%1 done.', [$tablename]));
        }
        return true;
    }
}