<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class DbRelationDups extends AbstractOptimize {

    /** Name function
     * @return string - name
     */
    public static function name(): string {
        return _m("Relation table duplicate records");
    }

    /** Description function
     * @return string - description
     */
    public static function description(): string {
        return _m("Testing if relation table contain records, where values in both columns are identical (which was bug fixed in Jan 2006)");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool {
        $SQL = 'SELECT count(*) as err_count FROM `relation` WHERE `source_id`=`destination_id`';
        $err_count = GetTable2Array($SQL, "aa_first", 'err_count');
        if ($err_count > 0) {
            $this->message(_m('%1 duplicates found', [$err_count]));
            return false;
        }
        $this->message(_m('No duplicates found'));
        return true;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
        $SQL = 'DELETE FROM `relation` WHERE `source_id`=`destination_id`';
        $db->query($SQL);
        freeDb($db);
        return true;
    }
}