<?php

namespace AA\Optimize;

use Cvarset;
use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;

/** Delete discussion comments for not existing items
 */
class ItemDiscussion extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Delete discussion comments for not existing items");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool {
        $SQL = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = GetTable2Array($SQL, "unpack:item_id", 'disc_count');
        if ($problems == false) {
            $this->message(_m('No problems found'));
            return true;
        }
        foreach ($problems as $item_id => $disc_count) {
            $this->message(_m('Problem for item %1 - %2 comments found', [$item_id, $disc_count]));
        }
        return false;
    }

    /** Repair the problem
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
        $SQL = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = GetTable2Array($SQL, "", 'item_id');
        if (count((array)$problems) < 1) {
            $this->message(_m('No problems found'));
            return true;
        }
        $SQL = 'DELETE FROM `discussion` WHERE ' . Cvarset::sqlin('item_id', $problems);
        $db->query($SQL);
        $SQL = "DELETE FROM `discussion` WHERE item_id = ''";
        $db->query($SQL);
        $this->message(_m('%1 problems solved', [count($problems)]));
        freeDb($db);
        return true;
    }
}