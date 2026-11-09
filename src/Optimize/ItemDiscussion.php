<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** Delete discussion comments for not existing items
 */
class ItemDiscussion extends AbstractOptimize
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Delete discussion comments for non-existent items");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool
    {
        $SQL = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = [];
        foreach (DB_AA::select([], $SQL) as $row) {
            $problems[unpack_id($row['item_id'])] = $row['disc_count'];
        }
        if (!$problems) {
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
    public function repair(): bool
    {
        $SQL = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = DB_AA::select('item_id', $SQL);   // packed ids, as stored
        if (!$problems) {
            $this->message(_m('No problems found'));
            return true;
        }
        DB_AA::delete('discussion', [['item_id', $problems, 'b']]);
        DB_AA::delete('discussion', [['item_id', '']]);
        $this->message(_m('%1 problems solved', [count($problems)]));
        return true;
    }
}
