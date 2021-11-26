<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;

/** There was change in Reader management functionality in AA v2.8.1 */
class DatabaseStructure extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Checks if all tables have right columns and indexes");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("We are time to time add new table or column to existing table in order we can support new features. This option will update the datastructure to the last one. No data will be lost.");
    }

    /** Name function
     * @return bool
     */
    public function test(): bool {
        $this->clear_report();
        $ret = true;  // which means OK

        $db = getDb();
        foreach ($db->table_names() as $table) {
            $table_name = $table['table_name'];
            $db->query("SHOW CREATE TABLE `$table_name`");
            if (!$db->next_record()) {
                continue;
            }
        }
        freeDb($db);
        return $ret;

    }

    /** Repair function
     * @return bool
     */
    public function repair(): bool { return true; }
}