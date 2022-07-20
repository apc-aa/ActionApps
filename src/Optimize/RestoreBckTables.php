<?php

namespace AA\Optimize;

use AA;
use function _m;
use function GetTable2Array;

/** Restore Data from Backup Tables
 *  This script DELETES all the current tables (slice, item, ...) where we have
 *  bck_table and renames all backup tables (bck_slice, bck_item, ...) to right
 *  names (slice, item, ...).
 **/
class RestoreBckTables extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Restore data from backup tables");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("[experimental] ") . _m("Deletes all the current tables (slice, item, ...) where we have bck_table and renames all backup tables (bck_slice, bck_item, ...) to right names (slice, item, ...).");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    function isType($type): bool { return in_array($type, []); }

    /** Test function
     * @return string - a message
     */
    public function test(): bool {
        $ret = true;

        $bck_exists = GetTable2Array("SHOW TABLES LIKE 'bck_content'", "aa_first");
        if (!$bck_exists) {
            $this->message(_m('The bck_ tables do not exist (or at least bck_content table)'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $metabase = AA::Metabase();
        $tablenames = $metabase->getTableNames();

        foreach ($tablenames as $tablename) {
            // checks if the backup table exist
            $bck_exists = GetTable2Array("SHOW TABLES LIKE 'bck_$tablename'", "aa_first");
            if (!$bck_exists) {
                // we do not have bck table
                $this->message(_m('There is no bck_%1 table - %1 not restored.', [$tablename]));
                continue;
            }
            $this->message(_m('Replace table bck_%1 -> %1', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `$tablename`");
            $this->query("ALTER TABLE `bck_$tablename` RENAME `$tablename`");
        }
        return true;
    }
}