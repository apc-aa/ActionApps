<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;

/** Fix inconcistency in pagecache
 *  Delete not existant keys in pagecache_str2find table
 */
class FixPagecache extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Fix inconcistency in pagecache");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Delete not existant keys in pagecache_str2find table");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        $row_count = GetTable2Array("SELECT count(*) as count FROM pagecache_str2find", "aa_first", 'count');
        // $wrong_count = GetTable2Array("SELECT count(*) as count FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL", "aa_first", 'count');
        $bad_rows = GetTable2Array("SELECT * FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL", "");
        if (!is_array($bad_rows)) {
            $bad_rows = [];
        }
        foreach ($bad_rows as $row) {
            $this->message(_m('id: %1, pagecache_id: %2, str2find: %3', [
                $row['id'],
                $row['pagecache_id'],
                $row['str2find'],
            ]));
        }
        $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', [
            count($bad_rows),
            $row_count,
        ]));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
        $db->query('DELETE pagecache_str2find FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL');
        $this->message(_m('Inconsistent rows in pagecache_str2find removed'));
        freeDb($db);
        return true;
    }
}