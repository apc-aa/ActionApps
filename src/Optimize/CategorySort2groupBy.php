<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class CategorySort2groupBy extends AbstractOptimize
{
    /** Name function
     * @return string - name
     */
    public static function name(): string
    {
        return _m("Convert slice.category_sort to slice.group_by");
    }

    /** Description function
     * @return string - description
     */
    public static function description(): string
    {
        return _m("In older version of AA we used just category fields for grouping items. Now it is universal, so boolean category_sort is not enough. We have used the newer group_by field for quite a long time so most probably all your slices are already converted.");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool
    {
        $SQL = "SELECT name FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slice_names = DB_AA::select('name', $SQL);
        if ($slice_names) {
            $this->message(_m('%1 slices are not converted', [count($slice_names)]) . '<br> &nbsp; ' . implode('<br> &nbsp; ', $slice_names));
            return false;
        }
        $this->message(_m('All slices are already converted'));
        return true;
    }

    /** Repair function
     * runs a series of SQL commands
     * @return bool
     */
    public function repair(): bool
    {
        $SQL = "SELECT id FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slices = DB_AA::select('id', $SQL);
        foreach ($slices as $p_slice_id) {
            $cat_field = DB_AA::select1('id', 'SELECT id FROM field', [['id', 'category.......%', 'LIKE'], ['slice_id', $p_slice_id, 'b']]);
            if ($cat_field) {
                // number 2 represents 'a' - ascending (because gb_direction in number)
                $set = [['group_by', $cat_field, 's'], ['gb_direction', 2, 'i'], ['gb_header', 0, 'i']];
            } else {
                $set = [['group_by', '', 's'], ['gb_direction', 0, 'i'], ['gb_header', 0, 'i']];
            }
            DB_AA::update('slice', $set, [['id', $p_slice_id, 'b']]);   // correct it
        }
        // fix all category_sort
        $SQL = "UPDATE slice SET category_sort=0";
        $db = getDb();
        $db->query($SQL);
        freeDb($db);
        return true;
    }
}
